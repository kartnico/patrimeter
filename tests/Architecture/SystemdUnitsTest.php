<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * systemd units shipped in docs/: a service restarted endlessly (`Restart=always`) never enters the "failed" state
 * if its restarts cannot reach the start limit, and its `OnFailure=` alert then never leaves.
 */
final class SystemdUnitsTest extends TestCase
{
    private const string SCHEDULER = __DIR__ . '/../../docs/systemd/patrimeter-scheduler.service';

    /** Default values of systemd (DefaultStartLimitIntervalSec, DefaultStartLimitBurst). */
    private const int DEFAULT_INTERVAL = 10;
    private const int DEFAULT_BURST = 5;

    public function testRestartedSchedulerFailsAndAlertsOnRepeatedFailures(): void
    {
        $unit = self::settings(self::SCHEDULER);
        self::assertSame('always', $unit['Restart'] ?? null);
        self::assertSame('patrimeter-alert@%n.service', $unit['OnFailure'] ?? null);

        $interval = (int) ($unit['StartLimitIntervalSec'] ?? self::DEFAULT_INTERVAL);
        $burst = (int) ($unit['StartLimitBurst'] ?? self::DEFAULT_BURST);
        $delay = (int) ($unit['RestartSec'] ?? 0);

        // Failures in a row, RestartSec apart, must exceed the limit within the interval.
        self::assertLessThan($interval, $burst * $delay, 'Les échecs répétés n\'atteignent jamais la limite de démarrages.');
        // The normal stop, once per hour (--time-limit=3600), must never reach it.
        self::assertStringContainsString('--time-limit=3600', $unit['ExecStart'] ?? '');
        self::assertLessThan(3600 * ($burst - 1), $interval);
    }

    /** @return array<string, string> settings of the unit, all sections together */
    private static function settings(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        $settings = [];
        foreach ($lines as $line) {
            if (preg_match('/^([A-Za-z]+)=(.*)$/', $line, $match) === 1) {
                $settings[$match[1]] = $match[2];
            }
        }

        return $settings;
    }
}
