<?php

declare(strict_types=1);

namespace Patrimeter\Application\Supervision;

use Patrimeter\Infrastructure\Persistence\DatabaseMaintenance;
use Patrimeter\Infrastructure\Supervision\Heartbeats;
use Patrimeter\Infrastructure\Supervision\LatestVersionCheck;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * State of the application for monitoring (docs/supervision.md), without any user data: database, last backup, last
 * scheduled tasks, free space, newer version published. An unreachable database makes the state fail; the rest only
 * warns, the application remaining usable.
 */
final class HealthReport
{
    public const string PASS = 'pass';
    public const string WARN = 'warn';
    public const string FAIL = 'fail';

    /** Daily backup (03:15): one day and two hours of margin. */
    public const int BACKUP_MAX_AGE = 26 * 3600;
    /** Scheduled tasks every hour: two missed runs. */
    public const int MAINTENANCE_MAX_AGE = 2 * 3600;
    /** Below this, the database and its backups may no longer be writable. */
    public const int DISK_MIN_FREE = 512 * 1024 * 1024;

    public function __construct(
        private readonly DatabaseMaintenance $database,
        private readonly Heartbeats $heartbeats,
        private readonly LatestVersionCheck $latestVersion,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.project_dir%/var')]
        private readonly string $dataDirectory,
    ) {}

    /**
     * @return array{status: string, now: int, database_up: bool, database_seconds: float, last_backup: ?int,
     *     last_maintenance: ?int, disk_free_bytes: ?int, update_available: ?string, checks: array<string, string>}
     */
    public function measure(): array
    {
        $start = (int) hrtime(true);
        $databaseUp = $this->database->isReachable();
        $databaseSeconds = (float) ((int) hrtime(true) - $start) / 1e9;
        $now = $this->clock->now()->getTimestamp();
        $lastBackup = $this->heartbeats->last(Heartbeats::BACKUP);
        $lastMaintenance = $this->heartbeats->last(Heartbeats::MAINTENANCE);
        $free = disk_free_space($this->dataDirectory);
        $diskFree = $free === false ? null : (int) $free;
        $update = $this->latestVersion->available();

        $checks = [
            'database' => $databaseUp ? self::PASS : self::FAIL,
            'backup' => $lastBackup !== null && $now - $lastBackup <= self::BACKUP_MAX_AGE ? self::PASS : self::WARN,
            'scheduler' => $lastMaintenance !== null && $now - $lastMaintenance <= self::MAINTENANCE_MAX_AGE ? self::PASS : self::WARN,
            'disk' => $diskFree === null || $diskFree >= self::DISK_MIN_FREE ? self::PASS : self::WARN,
            // Newer version published (daily check, when enabled): a warning, the application stays usable.
            'update' => $update === null ? self::PASS : self::WARN,
        ];
        $status = in_array(self::FAIL, $checks, true) ? self::FAIL : (in_array(self::WARN, $checks, true) ? self::WARN : self::PASS);

        return [
            'status' => $status,
            'now' => $now,
            'database_up' => $databaseUp,
            'database_seconds' => $databaseSeconds,
            'last_backup' => $lastBackup,
            'last_maintenance' => $lastMaintenance,
            'disk_free_bytes' => $diskFree,
            'update_available' => $update,
            'checks' => $checks,
        ];
    }
}
