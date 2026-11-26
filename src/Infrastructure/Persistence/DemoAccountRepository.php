<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;

/**
 * Demonstration accounts (`demo_accounts` table, DEMO_MODE): users without a password nor an email that can receive
 * anything, deleted with all their data (ON DELETE CASCADE) once expired.
 */
final class DemoAccountRepository
{
    /** Tables of the user's data counted by the demonstration quota. */
    private const array DATA_TABLES = ['accounts', 'statements', 'ownership_shares', 'budget_categories', 'budget_lines', 'budget_months', 'savings_goals'];

    public function __construct(private readonly Connection $connection) {}

    /** Demonstration user: address deemed confirmed (it can receive nothing), no password, no TOTP. */
    public function create(UserId $id, string $email, string $now, string $expiresAt): void
    {
        $this->connection->transactional(function () use ($id, $email, $now, $expiresAt): void {
            $this->connection->insert('users', [
                'id' => $id->value,
                'email' => $email,
                'password_hash' => null,
                'email_verified_at' => $now,
                'session_stamp' => bin2hex(random_bytes(16)),
                'created_at' => $now,
            ]);
            $this->connection->insert('demo_accounts', ['user_id' => $id->value, 'expires_at' => $expiresAt]);
        });
    }

    /** Demonstration accounts not expired yet at this date. */
    public function activeCount(string $now): int
    {
        return Row::int(['n' => $this->connection->fetchOne('SELECT COUNT(*) FROM demo_accounts WHERE expires_at > ?', [$now])], 'n');
    }

    /** End of the demonstration account that expires first after this date, or null. */
    public function nextExpiry(string $now): ?string
    {
        return Row::nullableString(['next' => $this->connection->fetchOne('SELECT MIN(expires_at) FROM demo_accounts WHERE expires_at > ?', [$now])], 'next');
    }

    /** Deletes the expired demonstration accounts and all their data; returns how many were deleted. */
    public function deleteExpired(string $now): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM users WHERE id IN (SELECT user_id FROM demo_accounts WHERE expires_at <= ?)',
            [$now],
        );
    }

    /** Deletes this demonstration account and all its data (never an ordinary account). */
    public function delete(UserId $user): void
    {
        $this->connection->executeStatement(
            'DELETE FROM users WHERE id = (SELECT user_id FROM demo_accounts WHERE user_id = ?)',
            [$user->value],
        );
    }

    /** Number of rows of data stored by the user: accounts, statements, shares, budget, goals. */
    public function storedRows(UserId $user): int
    {
        $counts = array_map(static fn(string $table): string => "(SELECT COUNT(*) FROM $table WHERE user_id = :user)", self::DATA_TABLES);

        return Row::int(['n' => $this->connection->fetchOne('SELECT ' . implode(' + ', $counts), ['user' => $user->value])], 'n');
    }
}
