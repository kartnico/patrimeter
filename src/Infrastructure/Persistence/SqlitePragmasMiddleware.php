<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use SensitiveParameter;

/**
 * SQLite settings applied to each connection (foreign keys are enabled by Doctrine's EnableForeignKeys
 * middleware):
 * - WAL journal: reads do not block the writing of an entry;
 * - wait 15 s on a lock instead of an error: on a slow or busy disk, a write can hold the database for several
 *   seconds, and 5 s made simultaneous entries fail (k6, 20 users);
 * - NORMAL synchronisation, safe in WAL mode and much faster than FULL.
 */
#[AsMiddleware(priority: 200)]
final class SqlitePragmasMiddleware implements Middleware
{
    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            #[\Override]
            public function connect(#[SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                if (($params['memory'] ?? false) !== true) {
                    $connection->exec('PRAGMA journal_mode = WAL');
                    $connection->exec('PRAGMA busy_timeout = 15000');
                    $connection->exec('PRAGMA synchronous = NORMAL');
                }

                return $connection;
            }
        };
    }
}
