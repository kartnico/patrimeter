<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;

/**
 * Envelopes of the data key (`key_envelopes` table): one per unlock method.
 *
 * @phpstan-type Envelope array{salt: string, ops_limit: ?int, mem_limit: ?int, wrapped_key: string}
 */
final class KeyEnvelopeRepository
{
    public function __construct(private readonly Connection $connection) {}

    /** @return Envelope|null */
    public function find(UserId $user, string $type, string $credentialId = ''): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT salt, ops_limit, mem_limit, wrapped_key FROM key_envelopes WHERE user_id = ? AND type = ? AND credential_id = ?',
            [$user->value, $type, $credentialId],
        );
        if ($row === false) {
            return null;
        }

        return [
            'salt' => Row::string($row, 'salt'),
            'ops_limit' => $row['ops_limit'] === null ? null : Row::int($row, 'ops_limit'),
            'mem_limit' => $row['mem_limit'] === null ? null : Row::int($row, 'mem_limit'),
            'wrapped_key' => Row::string($row, 'wrapped_key'),
        ];
    }

    /** @param Envelope $envelope */
    public function save(UserId $user, string $type, array $envelope, string $now, string $credentialId = ''): void
    {
        $this->connection->executeStatement(
            'INSERT INTO key_envelopes (user_id, type, credential_id, salt, ops_limit, mem_limit, wrapped_key, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(user_id, type, credential_id) DO UPDATE SET salt = excluded.salt, ops_limit = excluded.ops_limit,
                 mem_limit = excluded.mem_limit, wrapped_key = excluded.wrapped_key, created_at = excluded.created_at',
            [$user->value, $type, $credentialId, $envelope['salt'], $envelope['ops_limit'], $envelope['mem_limit'], $envelope['wrapped_key'], $now],
        );
    }

    public function exists(UserId $user, string $type): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM key_envelopes WHERE user_id = ? AND type = ? LIMIT 1', [$user->value, $type]) !== false;
    }

    public function hasAny(UserId $user): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM key_envelopes WHERE user_id = ? LIMIT 1', [$user->value]) !== false;
    }

    public function delete(UserId $user, string $type, string $credentialId = ''): void
    {
        $this->connection->executeStatement(
            'DELETE FROM key_envelopes WHERE user_id = ? AND type = ? AND credential_id = ?',
            [$user->value, $type, $credentialId],
        );
    }

    public function deleteAll(UserId $user): void
    {
        $this->connection->executeStatement('DELETE FROM key_envelopes WHERE user_id = ?', [$user->value]);
    }
}
