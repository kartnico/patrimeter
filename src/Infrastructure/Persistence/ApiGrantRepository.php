<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;

/**
 * Authorizations granted to a client or to a personal token (`api_grants` table) and the related tokens
 * (`api_tokens`): hash of the token, data key wrapped under a key derived from the token.
 *
 * @phpstan-type GrantRow array{id: string, user_id: UserId, client_id: ?string, name: string, scope: string, resource: string, fingerprint: string, expires_at: ?string, created_at: string, last_used_at: ?string}
 * @phpstan-type TokenRow array{selector: string, grant_id: string, kind: string, verifier_hash: string, wrapped_key: string, expires_at: string, used_at: ?string}
 */
final class ApiGrantRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function createGrant(string $id, UserId $user, ?string $clientId, string $name, string $scope, string $resource, string $fingerprint, ?string $expiresAt, string $now): void
    {
        $this->connection->insert('api_grants', [
            'id' => $id,
            'user_id' => $user->value,
            'client_id' => $clientId,
            'name' => $name,
            'scope' => $scope,
            'resource' => $resource,
            'fingerprint' => $fingerprint,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);
    }

    /** @return GrantRow|null */
    public function findGrant(string $id): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM api_grants WHERE id = ?', [$id]);

        return $row === false ? null : self::grant($row);
    }

    /** @return list<GrantRow> the user's authorizations, the most recent first */
    public function allOf(UserId $user): array
    {
        return array_map(
            self::grant(...),
            $this->connection->fetchAllAssociative('SELECT * FROM api_grants WHERE user_id = ? ORDER BY created_at DESC, id DESC', [$user->value]),
        );
    }

    /** True if the authorization did belong to this user. */
    public function revoke(UserId $user, string $id): bool
    {
        return $this->connection->executeStatement('DELETE FROM api_grants WHERE id = ? AND user_id = ?', [$id, $user->value]) === 1;
    }

    public function revokeById(string $id): void
    {
        $this->connection->executeStatement('DELETE FROM api_grants WHERE id = ?', [$id]);
    }

    public function touch(string $id, string $now): void
    {
        $this->connection->executeStatement('UPDATE api_grants SET last_used_at = ? WHERE id = ?', [$now, $id]);
    }

    public function saveToken(string $selector, string $grantId, string $kind, string $verifierHash, string $wrappedKey, string $expiresAt): void
    {
        $this->connection->insert('api_tokens', [
            'selector' => $selector,
            'grant_id' => $grantId,
            'kind' => $kind,
            'verifier_hash' => $verifierHash,
            'wrapped_key' => $wrappedKey,
            'expires_at' => $expiresAt,
        ]);
    }

    /** @return TokenRow|null */
    public function findToken(string $selector): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM api_tokens WHERE selector = ?', [$selector]);
        if ($row === false) {
            return null;
        }

        return [
            'selector' => Row::string($row, 'selector'),
            'grant_id' => Row::string($row, 'grant_id'),
            'kind' => Row::string($row, 'kind'),
            'verifier_hash' => Row::string($row, 'verifier_hash'),
            'wrapped_key' => Row::string($row, 'wrapped_key'),
            'expires_at' => Row::string($row, 'expires_at'),
            'used_at' => Row::nullableString($row, 'used_at'),
        ];
    }

    /** Atomic consumption of a refresh token. */
    public function markTokenUsed(string $selector, string $now): bool
    {
        return $this->connection->executeStatement('UPDATE api_tokens SET used_at = ? WHERE selector = ? AND used_at IS NULL', [$now, $selector]) === 1;
    }

    /** Deletes the expired tokens and authorizations. */
    public function purge(string $now): void
    {
        $this->connection->executeStatement('DELETE FROM api_tokens WHERE expires_at < ?', [$now]);
        $this->connection->executeStatement(
            'DELETE FROM api_grants WHERE (expires_at IS NOT NULL AND expires_at < ?) OR (expires_at IS NULL AND id NOT IN (SELECT grant_id FROM api_tokens))',
            [$now],
        );
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return GrantRow
     */
    private static function grant(array $row): array
    {
        return [
            'id' => Row::string($row, 'id'),
            'user_id' => UserId::fromString(Row::string($row, 'user_id')),
            'client_id' => Row::nullableString($row, 'client_id'),
            'name' => Row::string($row, 'name'),
            'scope' => Row::string($row, 'scope'),
            'resource' => Row::string($row, 'resource'),
            'fingerprint' => Row::string($row, 'fingerprint'),
            'expires_at' => Row::nullableString($row, 'expires_at'),
            'created_at' => Row::string($row, 'created_at'),
            'last_used_at' => Row::nullableString($row, 'last_used_at'),
        ];
    }
}
