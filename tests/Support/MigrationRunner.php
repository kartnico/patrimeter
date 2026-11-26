<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\AbstractSQLiteDriver\Middleware\EnableForeignKeys;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Provider\SchemaDiffProvider;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\Version;
use Patrimeter\Infrastructure\Persistence\Row;
use Patrimeter\Infrastructure\Persistence\SqlOnlySchemaDiffProvider;

/**
 * Applies the repository's migrations to a temporary SQLite database, without going through the kernel: serves the
 * migration tests (empty database, or copy of a database of an earlier version).
 */
final class MigrationRunner
{
    private const string MIGRATIONS_DIRECTORY = __DIR__ . '/../../migrations';

    public readonly Connection $connection;

    public function __construct(public readonly string $databaseFile)
    {
        $this->connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'path' => $databaseFile],
            (new DbalConfiguration())->setMiddlewares([new EnableForeignKeys()]),
        );

    }

    /**
     * New instance for each operation, like a console command: a migration already run is frozen by Doctrine and could
     * not be replayed backwards.
     */
    private function dependencies(): DependencyFactory
    {
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory('DoctrineMigrations', self::MIGRATIONS_DIRECTORY);
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());
        $configuration->setAllOrNothing(true);
        $configuration->setTransactional(true);

        $dependencies = DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($this->connection),
        );
        // Same setting as config/packages/doctrine_migrations.yaml.
        $dependencies->setService(SchemaDiffProvider::class, new SqlOnlySchemaDiffProvider());
        $dependencies->getMetadataStorage()->ensureInitialized();

        return $dependencies;
    }

    /** Creates a temporary database, possibly filled by an SQL script. */
    public static function temporary(?string $sqlFile = null): self
    {
        $file = sys_get_temp_dir() . '/patrimeter-migration-' . bin2hex(random_bytes(6)) . '.sqlite';
        if ($sqlFile !== null) {
            $sql = file_get_contents($sqlFile);
            if ($sql === false) {
                throw new \RuntimeException("Script SQL illisible : $sqlFile");
            }
            $pdo = new \PDO('sqlite:' . $file);
            $pdo->exec($sql);
        }

        return new self($file);
    }

    public function migrateToLatest(): void
    {
        $dependencies = $this->dependencies();
        $version = $dependencies->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan = $dependencies->getMigrationPlanCalculator()->getPlanUntilVersion($version);
        $dependencies->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    public function migrateDown(string $version): void
    {
        $dependencies = $this->dependencies();
        $plan = $dependencies->getMigrationPlanCalculator()->getPlanForVersions([new Version($version)], Direction::DOWN);
        $dependencies->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    /** @return list<string> versions run */
    public function executedVersions(): array
    {
        $versions = [];
        foreach ($this->dependencies()->getMetadataStorage()->getExecutedMigrations()->getItems() as $migration) {
            $versions[] = (string) $migration->getVersion();
        }

        return $versions;
    }

    public function newMigrationsCount(): int
    {
        return count($this->dependencies()->getMigrationStatusCalculator()->getNewMigrations());
    }

    /** @return list<array<string, mixed>> all the rows of a table, in the order of its columns */
    public function rows(string $table): array
    {
        return $this->connection->fetchAllAssociative(sprintf('SELECT * FROM %s ORDER BY 1, 2', $this->connection->quoteSingleIdentifier($table)));
    }

    /** @return array<string, string> name => SQL definition, for all the tables and indexes */
    public function schema(): array
    {
        $schema = [];
        foreach ($this->connection->fetchAllAssociative('SELECT name, sql FROM sqlite_master WHERE sql IS NOT NULL ORDER BY name') as $row) {
            $schema[Row::string($row, 'name')] = Row::string($row, 'sql');
        }

        return $schema;
    }

    /**
     * Schema comparable from one database to another: without comments, quotes nor spacing differences (a table
     * renamed by ALTER TABLE sees its name put in quotes).
     *
     * @return array<string, string>
     */
    public function normalizedSchema(): array
    {
        return array_map(
            static fn(string $sql): string => trim((string) preg_replace('/\s+/', ' ', str_replace('"', '', (string) preg_replace('/--[^\n]*/', '', $sql)))),
            $this->schema(),
        );
    }

    public function delete(): void
    {
        $this->connection->close();
        foreach ([$this->databaseFile, $this->databaseFile . '-wal', $this->databaseFile . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}
