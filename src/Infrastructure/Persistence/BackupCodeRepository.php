<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;

/** Backup codes (`backup_codes` table): only their hashes. */
final class BackupCodeRepository
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Replaces all the user's codes.
     *
     * @param list<string> $hashes
     */
    public function replaceAll(UserId $user, array $hashes, string $now): void
    {
        $this->connection->transactional(function () use ($user, $hashes, $now): void {
            $this->connection->executeStatement('DELETE FROM backup_codes WHERE user_id = ?', [$user->value]);
            foreach ($hashes as $hash) {
                $this->connection->insert('backup_codes', ['user_id' => $user->value, 'code_hash' => $hash, 'created_at' => $now]);
            }
        });
    }

    /** Marks the code as used if it was still valid: true if so (atomic). */
    public function consume(UserId $user, string $hash, string $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE backup_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$now, $user->value, $hash],
        ) === 1;
    }

    public function remaining(UserId $user): int
    {
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) AS remaining FROM backup_codes WHERE user_id = ? AND used_at IS NULL',
            [$user->value],
        );

        return $row === false ? 0 : Row::int($row, 'remaining');
    }

    public function deleteAll(UserId $user): void
    {
        $this->connection->executeStatement('DELETE FROM backup_codes WHERE user_id = ?', [$user->value]);
    }
}
