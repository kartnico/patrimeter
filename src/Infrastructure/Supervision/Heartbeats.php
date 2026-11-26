<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Supervision;

use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Monitoring markers: date of the last successful run of a background task, one file per task in var/supervision/.
 * The scheduled tasks write it themselves; the backup, run as root outside the application, writes it from its
 * script (docs/backup/patrimeter-backup.sh).
 */
final class Heartbeats
{
    public const string BACKUP = 'sauvegarde';
    public const string MAINTENANCE = 'maintenance';

    public function __construct(
        #[Autowire(param: 'app.supervision_dir')]
        private readonly string $directory,
        private readonly ClockInterface $clock,
    ) {}

    public function beat(string $name): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0o750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException("Dossier de supervision impossible à créer : {$this->directory}");
        }
        file_put_contents($this->directory . '/' . $name, (string) $this->clock->now()->getTimestamp(), LOCK_EX);
    }

    /** Unix timestamp of the last run, or null if the task never succeeded. */
    public function last(string $name): ?int
    {
        $file = $this->directory . '/' . $name;
        $content = is_readable($file) ? file_get_contents($file) : false;
        if ($content === false || preg_match('/\A\d{1,12}\s*\z/', $content) !== 1) {
            return null;
        }

        return (int) $content;
    }
}
