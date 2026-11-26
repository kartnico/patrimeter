<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * Counts the SQL queries run, in tests only, to detect N+1 queries. Static counter: the kernel of the test client is
 * restarted between two requests, with a new service.
 */
final class QueryCounter implements Middleware
{
    public static int $count = 0;

    #[\Override]
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            #[\Override]
            public function connect(array $params): DriverConnection
            {
                return new class (parent::connect($params)) extends AbstractConnectionMiddleware {
                    #[\Override]
                    public function prepare(string $sql): Statement
                    {
                        ++QueryCounter::$count;

                        return parent::prepare($sql);
                    }

                    #[\Override]
                    public function query(string $sql): Result
                    {
                        ++QueryCounter::$count;

                        return parent::query($sql);
                    }

                    #[\Override]
                    public function exec(string $sql): int|string
                    {
                        ++QueryCounter::$count;

                        return parent::exec($sql);
                    }
                };
            }
        };
    }
}
