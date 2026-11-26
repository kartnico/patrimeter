<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/** Maintenance of the SQLite database, run by the scheduled tasks. */
final class DatabaseMaintenance
{
    public function __construct(private readonly Connection $connection) {}

    /** True if the database answers a query (monitoring). */
    public function isReachable(): bool
    {
        try {
            return $this->connection->fetchOne('SELECT 1') !== false;
        } catch (\Doctrine\DBAL\Exception) {
            return false;
        }
    }

    /** Updates the statistics SQLite uses to choose its indexes (recommended periodically). */
    public function optimize(): void
    {
        $this->connection->executeStatement('PRAGMA optimize');
    }
}
