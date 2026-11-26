<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Test on the real container and the SQLite test database. Each test runs in a transaction rolled back at the end
 * (dama/doctrine-test-bundle); the clock is frozen on 15 November 2026 at noon (config/services.yaml, when@test).
 */
abstract class IntegrationTestCase extends KernelTestCase
{
    use CreatesAccounts;

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected static function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    protected static function clock(): MockClock
    {
        // Frozen clock of the test container (config/services.yaml, when@test).
        return self::getContainer()->get('clock');
    }
}
