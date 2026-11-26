<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;

/**
 * Users (`users` table). The TOTP secret is stored encrypted: this repository only handles the encrypted value (see
 * Patrimeter\Security\AuthSecrets).
 *
 * @phpstan-type UserRow array{id: UserId, email: ?string, password_hash: ?string, email_verified: bool, totp_secret: ?string, totp_enabled: bool, session_stamp: string, demo_expires_at: ?string}
 */
final class UserRepository
{
    /** With the end of a demonstration account (demo_accounts), null for any other user. */
    private const string SELECT_USER = 'SELECT users.id, users.email, users.password_hash, users.email_verified_at, users.totp_secret, users.totp_enabled_at, users.session_stamp, demo_accounts.expires_at AS demo_expires_at FROM users LEFT JOIN demo_accounts ON demo_accounts.user_id = users.id';

    public function __construct(private readonly Connection $connection) {}

    /** @return UserRow|null */
    public function find(UserId $id): ?array
    {
        $row = $this->connection->fetchAssociative(self::SELECT_USER . ' WHERE users.id = ?', [$id->value]);

        return $row === false ? null : self::hydrate($row);
    }

    /** @return UserRow|null normalised address expected */
    public function findByEmail(string $email): ?array
    {
        $row = $this->connection->fetchAssociative(self::SELECT_USER . ' WHERE users.email = ?', [$email]);

        return $row === false ? null : self::hydrate($row);
    }

    public function create(UserId $id, string $email, ?string $passwordHash, ?string $verifiedAt, string $now): void
    {
        $this->connection->insert('users', [
            'id' => $id->value,
            'email' => $email,
            'password_hash' => $passwordHash,
            'email_verified_at' => $verifiedAt,
            'session_stamp' => self::newStamp(),
            'created_at' => $now,
        ]);
    }

    /** Whether the instance has an account of its own (demonstration accounts aside). */
    public function hasAccount(): bool
    {
        return $this->connection->fetchOne(
            'SELECT 1 FROM users WHERE NOT EXISTS (SELECT 1 FROM demo_accounts WHERE demo_accounts.user_id = users.id) LIMIT 1',
        ) !== false;
    }

    /**
     * First account of the instance (installation wizard), address deemed confirmed: written only if the instance still
     * has none, in a single statement (SQLite writes one at a time), so that two visitors cannot both create one.
     * Returns whether it was created.
     */
    public function createFirst(UserId $id, string $email, string $passwordHash, string $now): bool
    {
        return $this->connection->executeStatement(
            'INSERT INTO users (id, email, password_hash, email_verified_at, session_stamp, created_at)
             SELECT ?, ?, ?, ?, ?, ?
             WHERE NOT EXISTS (SELECT 1 FROM users WHERE NOT EXISTS (SELECT 1 FROM demo_accounts WHERE demo_accounts.user_id = users.id))',
            [$id->value, $email, $passwordHash, $now, self::newStamp(), $now],
        ) === 1;
    }

    /** Renewed registration of an address never confirmed: new password. */
    public function replacePendingPassword(UserId $id, string $passwordHash): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET password_hash = ?, session_stamp = ? WHERE id = ? AND email_verified_at IS NULL',
            [$passwordHash, self::newStamp(), $id->value],
        );
    }

    public function markEmailVerified(UserId $id, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET email_verified_at = ? WHERE id = ? AND email_verified_at IS NULL',
            [$now, $id->value],
        );
    }

    public function emailExists(string $email): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM users WHERE email = ?', [$email]) !== false;
    }

    /** Language of the emails chosen in the profile ("fr" or "en"); null: that of the interface when sending. */
    public function emailLocale(UserId $id): ?string
    {
        return self::locale($this->connection->fetchOne('SELECT email_locale FROM users WHERE id = ?', [$id->value]));
    }

    /** Language of the emails of the account with this address, if it chose one. */
    public function emailLocaleOf(string $email): ?string
    {
        return self::locale($this->connection->fetchOne('SELECT email_locale FROM users WHERE email = ?', [$email]));
    }

    /** Language read, or null (none chosen, or no such account). */
    private static function locale(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    public function saveEmailLocale(UserId $id, ?string $locale): void
    {
        $this->connection->executeStatement('UPDATE users SET email_locale = ? WHERE id = ?', [$locale, $id->value]);
    }

    /**
     * New hash of the password. The user's other sessions end: the hash is part of what is compared on each request
     * (User::isEqualTo).
     */
    public function updatePasswordHash(UserId $id, string $passwordHash): void
    {
        $this->connection->executeStatement('UPDATE users SET password_hash = ? WHERE id = ?', [$passwordHash, $id->value]);
    }

    /** @return array<string, string> encrypted TOTP secret, per user (rotation of AUTH_SECRET_KEY) */
    public function totpSecrets(): array
    {
        $secrets = [];
        foreach ($this->connection->fetchAllAssociative('SELECT id, totp_secret FROM users WHERE totp_secret IS NOT NULL') as $row) {
            $secrets[Row::string($row, 'id')] = Row::string($row, 'totp_secret');
        }

        return $secrets;
    }

    public function replaceTotpSecret(UserId $id, string $encryptedSecret): void
    {
        $this->connection->executeStatement('UPDATE users SET totp_secret = ? WHERE id = ?', [$encryptedSecret, $id->value]);
    }

    /** Enables TOTP with this (encrypted) secret. */
    public function enableTotp(UserId $id, string $encryptedSecret, string $now): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET totp_secret = ?, totp_enabled_at = ? WHERE id = ?',
            [$encryptedSecret, $now, $id->value],
        );
    }

    /** Disables TOTP (reset by the administrator) and ends the sessions. */
    public function disableTotp(UserId $id): void
    {
        $this->connection->executeStatement(
            'UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, session_stamp = ? WHERE id = ?',
            [self::newStamp(), $id->value],
        );
    }

    /** Ends all the user's open sessions; returns the new stamp. */
    /**
     * Deletes the user and, through the foreign keys (ON DELETE CASCADE), all their data: accounts, statements,
     * shares, budget, Carbure link, family members, authentication means, tokens and sign-in log.
     */
    public function delete(UserId $id): void
    {
        $this->connection->executeStatement('DELETE FROM users WHERE id = ?', [$id->value]);
    }

    /**
     * Deletes the registrations never confirmed, and everything tied to them (ON DELETE CASCADE): address not
     * confirmed, created before $createdBefore, without any confirmation link still valid at $now (a registration
     * done again sends a new link), without second factor nor account. Never an account without address nor a
     * demonstration account (their own expiry). Returns the number of deleted accounts.
     */
    public function deleteUnconfirmed(string $createdBefore, string $now, string $verificationPurpose): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM users
              WHERE email IS NOT NULL AND email_verified_at IS NULL AND totp_enabled_at IS NULL AND created_at < ?
                AND NOT EXISTS (SELECT 1 FROM one_time_tokens t
                                 WHERE t.user_id = users.id AND t.purpose = ? AND t.used_at IS NULL AND t.expires_at >= ?)
                AND NOT EXISTS (SELECT 1 FROM demo_accounts d WHERE d.user_id = users.id)
                AND NOT EXISTS (SELECT 1 FROM accounts a WHERE a.user_id = users.id)',
            [$createdBefore, $verificationPurpose, $now],
        );
    }

    /**
     * Every user, for the administrator (app:user:list), without decrypting anything: address, dates, second factor,
     * demonstration, number of accounts (rows of the accounts table, whose content stays encrypted) and last sign-in.
     *
     * @param list<string> $signInTypes types of the sign-in log that count as a sign-in
     *
     * @return list<array{email: ?string, created_at: string, email_verified_at: ?string, totp_enabled_at: ?string, demo: bool, accounts: int, last_sign_in: ?string}>
     */
    public function summaries(array $signInTypes): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT u.email, u.created_at, u.email_verified_at, u.totp_enabled_at,
                    EXISTS (SELECT 1 FROM demo_accounts d WHERE d.user_id = u.id) AS demo,
                    (SELECT COUNT(*) FROM accounts a WHERE a.user_id = u.id) AS accounts,
                    (SELECT MAX(e.created_at) FROM auth_events e WHERE e.user_id = u.id AND e.success = 1 AND e.type IN (?)) AS last_sign_in
               FROM users u
              ORDER BY u.created_at, u.email',
            [$signInTypes],
            [ArrayParameterType::STRING],
        );

        return array_map(static fn(array $row): array => [
            'email' => Row::nullableString($row, 'email'),
            'created_at' => Row::string($row, 'created_at'),
            'email_verified_at' => Row::nullableString($row, 'email_verified_at'),
            'totp_enabled_at' => Row::nullableString($row, 'totp_enabled_at'),
            'demo' => Row::int($row, 'demo') === 1,
            'accounts' => Row::int($row, 'accounts'),
            'last_sign_in' => Row::nullableString($row, 'last_sign_in'),
        ], $rows);
    }

    public function renewSessionStamp(UserId $id): string
    {
        $stamp = self::newStamp();
        $this->connection->executeStatement('UPDATE users SET session_stamp = ? WHERE id = ?', [$stamp, $id->value]);

        return $stamp;
    }

    private static function newStamp(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return UserRow
     */
    private static function hydrate(array $row): array
    {
        return [
            'id' => UserId::fromString(Row::string($row, 'id')),
            'email' => Row::nullableString($row, 'email'),
            'password_hash' => Row::nullableString($row, 'password_hash'),
            'email_verified' => Row::nullableString($row, 'email_verified_at') !== null,
            'totp_secret' => Row::nullableString($row, 'totp_secret'),
            'totp_enabled' => Row::nullableString($row, 'totp_enabled_at') !== null,
            'session_stamp' => Row::string($row, 'session_stamp'),
            'demo_expires_at' => Row::nullableString($row, 'demo_expires_at'),
        ];
    }
}
