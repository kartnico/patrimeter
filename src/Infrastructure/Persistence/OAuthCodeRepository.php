<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;

/**
 * Authorization codes (`oauth_codes` table): single-use, very short-lived. The data key is wrapped there under a key
 * derived from the code, which only the client receives.
 *
 * @phpstan-type CodeRow array{user_id: UserId, client_id: string, redirect_uri: string, code_challenge: string, scope: string, resource: string, verifier_hash: string, wrapped_key: string, expires_at: string, used_at: ?string}
 */
final class OAuthCodeRepository
{
    public function __construct(private readonly Connection $connection) {}

    public function create(string $selector, UserId $user, string $clientId, string $redirectUri, string $challenge, string $scope, string $resource, string $verifierHash, string $wrappedKey, string $expiresAt): void
    {
        $this->connection->insert('oauth_codes', [
            'selector' => $selector,
            'user_id' => $user->value,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'scope' => $scope,
            'resource' => $resource,
            'verifier_hash' => $verifierHash,
            'wrapped_key' => $wrappedKey,
            'expires_at' => $expiresAt,
        ]);
    }

    /** @return CodeRow|null */
    public function find(string $selector): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM oauth_codes WHERE selector = ?', [$selector]);
        if ($row === false) {
            return null;
        }

        return [
            'user_id' => UserId::fromString(Row::string($row, 'user_id')),
            'client_id' => Row::string($row, 'client_id'),
            'redirect_uri' => Row::string($row, 'redirect_uri'),
            'code_challenge' => Row::string($row, 'code_challenge'),
            'scope' => Row::string($row, 'scope'),
            'resource' => Row::string($row, 'resource'),
            'verifier_hash' => Row::string($row, 'verifier_hash'),
            'wrapped_key' => Row::string($row, 'wrapped_key'),
            'expires_at' => Row::string($row, 'expires_at'),
            'used_at' => Row::nullableString($row, 'used_at'),
        ];
    }

    /** Atomic consumption: true for the only call that actually consumed it. */
    public function markUsed(string $selector, string $now): bool
    {
        return $this->connection->executeStatement('UPDATE oauth_codes SET used_at = ? WHERE selector = ? AND used_at IS NULL', [$now, $selector]) === 1;
    }

    public function purge(string $before): void
    {
        $this->connection->executeStatement('DELETE FROM oauth_codes WHERE expires_at < ?', [$before]);
    }
}
