<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Integration;

use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\DriverManager;
use Patrimeter\Infrastructure\Persistence\SqlitePragmasMiddleware;
use PHPUnit\Framework\TestCase;

final class SqlitePragmasMiddlewareTest extends TestCase
{
    private string $file;

    #[\Override]
    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . '/patrimeter-pragmas-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    #[\Override]
    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testAppliesWalBusyTimeoutAndSynchronous(): void
    {
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'path' => $this->file],
            (new Configuration())->setMiddlewares([new SqlitePragmasMiddleware()]),
        );

        self::assertSame('wal', $connection->fetchOne('PRAGMA journal_mode'));
        self::assertEquals(15000, $connection->fetchOne('PRAGMA busy_timeout'), 'une saisie attend un verrou jusqu\'à 15 s au lieu d\'échouer');
        self::assertEquals(1, $connection->fetchOne('PRAGMA synchronous'), 'NORMAL = 1');
    }

    public function testInMemoryDatabaseIsLeftUntouched(): void
    {
        $connection = DriverManager::getConnection(
            ['driver' => 'pdo_sqlite', 'memory' => true],
            (new Configuration())->setMiddlewares([new SqlitePragmasMiddleware()]),
        );

        self::assertSame('memory', $connection->fetchOne('PRAGMA journal_mode'));
    }
}
