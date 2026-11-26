<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;

/**
 * Trusted browsers of the users (`trusted_browsers` table): selector, fingerprint of the cookie's secret, fingerprint
 * of the credentials, address and browser, dates. Every query of a user's browsers filters on `user_id`: a selector of
 * another user's browser matches nothing.
 *
 * @phpstan-type BrowserRow array{id: string, verifier_hash: string, fingerprint: string, ip: ?string, user_agent: ?string, created_at: string, last_used_at: ?string, expires_at: string}
 */
final class TrustedBrowserRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function create(UserId $user, string $id, string $verifierHash, string $fingerprint, ?string $ip, ?string $userAgent, string $now, string $expiresAt): void
    {
        $this->connection->insert('trusted_browsers', [
            'id' => $id,
            'user_id' => $user->value,
            'verifier_hash' => $verifierHash,
            'fingerprint' => $fingerprint,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'created_at' => $now,
            'expires_at' => $expiresAt,
        ]);
    }

    /** @return BrowserRow|null */
    public function find(UserId $user, string $id): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT id, verifier_hash, fingerprint, ip, user_agent, created_at, last_used_at, expires_at FROM trusted_browsers WHERE id = ? AND user_id = ?',
            [$id, $user->value],
        );

        return $row === false ? null : self::row($row);
    }

    public function touch(UserId $user, string $id, string $now): void
    {
        $this->connection->executeStatement('UPDATE trusted_browsers SET last_used_at = ? WHERE id = ? AND user_id = ?', [$now, $id, $user->value]);
    }

    /**
     * Browsers still trusted at $now, the most recently used first.
     *
     * @return list<BrowserRow>
     */
    public function current(UserId $user, string $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, verifier_hash, fingerprint, ip, user_agent, created_at, last_used_at, expires_at FROM trusted_browsers
              WHERE user_id = ? AND expires_at >= ? ORDER BY COALESCE(last_used_at, created_at) DESC, created_at DESC',
            [$user->value, $now],
        );

        return array_map(self::row(...), $rows);
    }

    /** Forgets one browser of the user; false if it is unknown or someone else's. */
    public function delete(UserId $user, string $id): bool
    {
        return $this->connection->executeStatement('DELETE FROM trusted_browsers WHERE id = ? AND user_id = ?', [$id, $user->value]) === 1;
    }

    public function deleteAll(UserId $user): int
    {
        return (int) $this->connection->executeStatement('DELETE FROM trusted_browsers WHERE user_id = ?', [$user->value]);
    }

    /** Forgets the expired browsers, whatever their user (scheduled task). */
    public function purge(string $now): void
    {
        $this->connection->executeStatement('DELETE FROM trusted_browsers WHERE expires_at < ?', [$now]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return BrowserRow
     */
    private static function row(array $row): array
    {
        return [
            'id' => Row::string($row, 'id'),
            'verifier_hash' => Row::string($row, 'verifier_hash'),
            'fingerprint' => Row::string($row, 'fingerprint'),
            'ip' => Row::nullableString($row, 'ip'),
            'user_agent' => Row::nullableString($row, 'user_agent'),
            'created_at' => Row::string($row, 'created_at'),
            'last_used_at' => Row::nullableString($row, 'last_used_at'),
            'expires_at' => Row::string($row, 'expires_at'),
        ];
    }
}
