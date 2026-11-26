<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;

/**
 * Single-use tokens sent by email (`one_time_tokens` table): the selector serves to find the row, only the hash of
 * the verifier is kept.
 *
 * @phpstan-type TokenRow array{user_id: UserId, verifier_hash: string, expires_at: string, used_at: ?string}
 */
final class OneTimeTokenRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function create(string $selector, UserId $user, string $purpose, string $verifierHash, string $expiresAt, string $now): void
    {
        $this->connection->insert('one_time_tokens', [
            'selector' => $selector,
            'user_id' => $user->value,
            'purpose' => $purpose,
            'verifier_hash' => $verifierHash,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);
    }

    /** @return TokenRow|null */
    public function find(string $selector, string $purpose): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT user_id, verifier_hash, expires_at, used_at FROM one_time_tokens WHERE selector = ? AND purpose = ?',
            [$selector, $purpose],
        );
        if ($row === false) {
            return null;
        }

        return [
            'user_id' => UserId::fromString(Row::string($row, 'user_id')),
            'verifier_hash' => Row::string($row, 'verifier_hash'),
            'expires_at' => Row::string($row, 'expires_at'),
            'used_at' => Row::nullableString($row, 'used_at'),
        ];
    }

    /** Consumes the token if it has not been yet: true if so (atomic). */
    public function consume(string $selector, string $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE one_time_tokens SET used_at = ? WHERE selector = ? AND used_at IS NULL',
            [$now, $selector],
        ) === 1;
    }

    /** Invalidates all this user's tokens still valid for this purpose. */
    public function revokeAll(UserId $user, string $purpose, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE one_time_tokens SET used_at = ? WHERE user_id = ? AND purpose = ? AND used_at IS NULL',
            [$now, $user->value, $purpose],
        );
    }

    /** Deletes the tokens expired or used for more than one day. */
    public function purge(string $before): void
    {
        $this->connection->executeStatement(
            'DELETE FROM one_time_tokens WHERE expires_at < ? OR (used_at IS NOT NULL AND used_at < ?)',
            [$before, $before],
        );
    }
}
