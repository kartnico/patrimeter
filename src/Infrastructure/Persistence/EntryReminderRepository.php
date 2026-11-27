<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\UserId;

/**
 * Monthly reminders to enter the balances (`entry_reminders` table): day, language, last month sent, fingerprint of the
 * unsubscribe link. Whether the month is already entered is read from the months of the statements, which are in clear
 * (never an amount).
 */
final class EntryReminderRepository
{
    public function __construct(private readonly Connection $connection) {}

    /** @return array{day: int, locale: string}|null */
    public function find(UserId $user): ?array
    {
        $row = $this->connection->fetchAssociative('SELECT day, locale FROM entry_reminders WHERE user_id = ?', [$user->value]);

        return $row === false ? null : ['day' => Row::int($row, 'day'), 'locale' => Row::string($row, 'locale')];
    }

    public function save(UserId $user, int $day, string $locale): void
    {
        $this->connection->executeStatement(
            'INSERT INTO entry_reminders (user_id, day, locale) VALUES (?, ?, ?)
             ON CONFLICT(user_id) DO UPDATE SET day = excluded.day, locale = excluded.locale',
            [$user->value, $day, $locale],
        );
    }

    public function delete(UserId $user): void
    {
        $this->connection->executeStatement('DELETE FROM entry_reminders WHERE user_id = ?', [$user->value]);
    }

    /** Deletes the reminder whose last email carried this unsubscribe link; false if none. */
    public function deleteByUnsubscribeHash(string $hash): bool
    {
        return $this->connection->executeStatement('DELETE FROM entry_reminders WHERE unsubscribe_hash = ?', [$hash]) > 0;
    }

    public function existsWithUnsubscribeHash(string $hash): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM entry_reminders WHERE unsubscribe_hash = ?', [$hash]) !== false;
    }

    /**
     * Reminders to send for this month on this day: day reached, not sent this month yet, address confirmed, not a
     * demonstration account, and no statement entered for the month.
     *
     * @return list<array{user: UserId, email: string, locale: string}>
     */
    public function due(string $month, int $day): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.user_id, u.email, COALESCE(u.email_locale, r.locale) AS locale FROM entry_reminders r JOIN users u ON u.id = r.user_id
             WHERE r.day <= ? AND (r.last_sent_month IS NULL OR r.last_sent_month <> ?)
               AND u.email IS NOT NULL AND u.email_verified_at IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM demo_accounts d WHERE d.user_id = r.user_id)
               AND NOT EXISTS (SELECT 1 FROM statements s WHERE s.user_id = r.user_id AND s.month = ?)
             ORDER BY r.user_id',
            [$day, $month, $month],
        );

        return array_map(static fn(array $row): array => [
            'user' => UserId::fromString(Row::string($row, 'user_id')),
            'email' => Row::string($row, 'email'),
            'locale' => Row::string($row, 'locale'),
        ], $rows);
    }

    /** A crashed sender may be replaced after one hour; no database lock is held across SMTP. */
    public function claim(UserId $user, string $month, string $hash, int $now): bool
    {
        return $this->connection->executeStatement(
            'UPDATE entry_reminders SET claim_hash = ?, claim_until = ? WHERE user_id = ?
             AND (last_sent_month IS NULL OR last_sent_month <> ?) AND (claim_until IS NULL OR claim_until <= ?)',
            [$hash, $now + 3600, $user->value, $month, $now],
        ) === 1;
    }

    public function release(UserId $user, string $hash): void
    {
        $this->connection->executeStatement('UPDATE entry_reminders SET claim_hash = NULL, claim_until = NULL WHERE user_id = ? AND claim_hash = ?', [$user->value, $hash]);
    }

    public function markSent(UserId $user, string $month, string $unsubscribeHash): void
    {
        $this->connection->executeStatement(
            'UPDATE entry_reminders SET last_sent_month = ?, unsubscribe_hash = ?, claim_hash = NULL, claim_until = NULL WHERE user_id = ? AND claim_hash = ?',
            [$month, $unsubscribeHash, $user->value, $unsubscribeHash],
        );
    }
}
