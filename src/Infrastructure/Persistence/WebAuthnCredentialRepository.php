<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;

/**
 * Passkeys (FIDO2) and security keys (`webauthn_credentials` table). Any read or write of a signed-in user filters on
 * `user_id`; only the passkey sign-in looks up a credential without knowing the user yet.
 *
 * @phpstan-type Credential array{credential_id: string, user_id: UserId, usage: string, label: string, record: string, created_at: string, last_used_at: ?string}
 */
final class WebAuthnCredentialRepository
{
    private const string COLUMNS = 'credential_id, user_id, usage, label, record, created_at, last_used_at';

    public function __construct(private readonly Connection $connection) {}

    /** @return Credential|null */
    public function findById(string $credentialId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM webauthn_credentials WHERE credential_id = ?', [$credentialId]);

        return $row === false ? null : self::hydrate($row);
    }

    /** @return Credential|null */
    public function find(UserId $owner, string $credentialId): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT ' . self::COLUMNS . ' FROM webauthn_credentials WHERE user_id = ? AND credential_id = ?', [$owner->value, $credentialId]);

        return $row === false ? null : self::hydrate($row);
    }

    /** @return list<Credential> from the oldest to the most recent */
    public function all(UserId $owner): array
    {
        return array_map(
            self::hydrate(...),
            $this->connection->fetchAllAssociative('SELECT ' . self::COLUMNS . ' FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at, credential_id', [$owner->value]),
        );
    }

    public function add(UserId $owner, string $credentialId, string $usage, string $label, string $record, string $now): void
    {
        $this->connection->insert('webauthn_credentials', [
            'credential_id' => $credentialId,
            'user_id' => $owner->value,
            'usage' => $usage,
            'label' => $label,
            'record' => $record,
            'created_at' => $now,
        ]);
    }

    public function recordUse(UserId $owner, string $credentialId, string $record, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE webauthn_credentials SET record = ?, last_used_at = ? WHERE user_id = ? AND credential_id = ?',
            [$record, $now, $owner->value, $credentialId],
        );
    }

    public function rename(UserId $owner, string $credentialId, string $label): void
    {
        $this->connection->executeStatement('UPDATE webauthn_credentials SET label = ? WHERE user_id = ? AND credential_id = ?', [$label, $owner->value, $credentialId]);
    }

    public function delete(UserId $owner, string $credentialId): void
    {
        $this->connection->executeStatement('DELETE FROM webauthn_credentials WHERE user_id = ? AND credential_id = ?', [$owner->value, $credentialId]);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return Credential
     */
    private static function hydrate(array $row): array
    {
        return [
            'credential_id' => Row::string($row, 'credential_id'),
            'user_id' => UserId::fromString(Row::string($row, 'user_id')),
            'usage' => Row::string($row, 'usage'),
            'label' => Row::string($row, 'label'),
            'record' => Row::string($row, 'record'),
            'created_at' => Row::string($row, 'created_at'),
            'last_used_at' => Row::nullableString($row, 'last_used_at'),
        ];
    }
}
