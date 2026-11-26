<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Provider\SchemaDiffProvider;

/**
 * Disables the automatic schema comparison of Doctrine Migrations: Patrimeter's migrations are written in explicit
 * SQL only (addSql), reviewed and tested.
 *
 * Why: under SQLite, Doctrine introspects unnamed foreign keys badly and believes the schema changed; it then
 * generates a table rebuild whose DROP TABLE triggers the ON DELETE CASCADE, which erased all the statements of a v1
 * database (see MigrationTest).
 *
 * The SchemaDiffProvider interface can be replaced through the bundle's configuration (services); MigrationTest
 * checks on a real v1 database that this replacement stays in effect.
 */
final class SqlOnlySchemaDiffProvider implements SchemaDiffProvider
{
    /** Empty schema: the $schema parameter of up()/down() is never used by our migrations. */
    #[\Override]
    public function createFromSchema(): Schema
    {
        return Schema::editor()->create();
    }

    #[\Override]
    public function createToSchema(Schema $fromSchema): Schema
    {
        return clone $fromSchema;
    }

    /** @return list<string> */
    #[\Override]
    public function getSqlDiffToMigrate(Schema $fromSchema, Schema $toSchema): array
    {
        return [];
    }
}
