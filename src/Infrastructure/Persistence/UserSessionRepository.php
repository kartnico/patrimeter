<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;

/**
 * Signed-in sessions of the users (`user_sessions` table): random identifier, address and browser, start, last activity
 * and revocation. Every query of a user's sessions filters on `user_id`: an identifier of another user's session
 * matches nothing.
 *
 * @phpstan-type SessionRow array{id: string, ip: ?string, user_agent: ?string, created_at: string, last_seen_at: string}
 */
final class UserSessionRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function create(UserId $user, string $id, ?string $ip, ?string $userAgent, string $now): void
    {
        $this->connection->insert('user_sessions', [
            'id' => $id,
            'user_id' => $user->value,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'created_at' => $now,
            'last_seen_at' => $now,
        ]);
    }

    /** True if the session exists and has not been signed out. */
    public function isActive(UserId $user, string $id): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM user_sessions WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [$id, $user->value],
        ) !== false;
    }

    /** Records the last activity; false if the session no longer exists or has been signed out. */
    public function touch(UserId $user, string $id, string $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE user_sessions SET last_seen_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [$now, $id, $user->value],
        ) === 1;
    }

    /**
     * Sessions not signed out, active since $activeSince and started since $startedSince, the most recent first.
     *
     * @return list<SessionRow>
     */
    public function active(UserId $user, string $activeSince, string $startedSince): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, ip, user_agent, created_at, last_seen_at FROM user_sessions
              WHERE user_id = ? AND revoked_at IS NULL AND last_seen_at >= ? AND created_at >= ?
              ORDER BY last_seen_at DESC, created_at DESC',
            [$user->value, $activeSince, $startedSince],
        );

        return array_map(static fn(array $row): array => [
            'id' => Row::string($row, 'id'),
            'ip' => Row::nullableString($row, 'ip'),
            'user_agent' => Row::nullableString($row, 'user_agent'),
            'created_at' => Row::string($row, 'created_at'),
            'last_seen_at' => Row::string($row, 'last_seen_at'),
        ], $rows);
    }

    /** Signs out one session of the user; false if it is unknown, someone else's or already signed out. */
    public function revoke(UserId $user, string $id, string $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE user_sessions SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL',
            [$now, $id, $user->value],
        ) === 1;
    }

    /** Signs out all the user's sessions except $keep (null: all of them). */
    public function revokeAll(UserId $user, ?string $keep, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND id <> ?',
            [$now, $user->value, $keep ?? ''],
        );
    }

    /** Forgets a session at sign-out. */
    public function delete(UserId $user, string $id): void
    {
        $this->connection->executeStatement('DELETE FROM user_sessions WHERE id = ? AND user_id = ?', [$id, $user->value]);
    }

    /** Forgets the sessions without activity since $before, whatever their user (scheduled task). */
    public function purgeInactive(string $before): void
    {
        $this->connection->executeStatement('DELETE FROM user_sessions WHERE last_seen_at < ?', [$before]);
    }
}
