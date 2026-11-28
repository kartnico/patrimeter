<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Domain\Valuation;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Security\Vault\Vault;

/**
 * Monthly statements of a user: one value in cents per account and per month, encrypted with their data key (the
 * month and the account stay in clear).
 *
 * Isolation: each query filters on `user_id`, and the database refuses a statement whose account belongs to another
 * user (composite foreign key `(account_id, user_id)`).
 */
final class StatementRepository
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Vault $vault,
    ) {}

    /** @return array<int, array<string, int>> account => (YYYY-MM => cents), ascending months */
    public function all(UserId $owner): array
    {
        $result = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT account_id, month, ciphertext FROM statements WHERE user_id = ? ORDER BY account_id, month',
            [$owner->value],
        );
        foreach ($rows as $row) {
            $accountId = Row::int($row, 'account_id');
            $month = Row::string($row, 'month');
            $result[$accountId][$month] = $this->amount($owner, $accountId, $month, Row::string($row, 'ciphertext'));
        }

        return $result;
    }

    public function hasBefore(UserId $owner, int $accountId, Month $month): bool
    {
        return $this->connection->fetchOne('SELECT 1 FROM statements WHERE user_id = ? AND account_id = ? AND month < ? LIMIT 1', [$owner->value, $accountId, $month->toString()]) !== false;
    }

    public function valuation(UserId $owner): Valuation
    {
        return new Valuation($this->all($owner));
    }

    /** Only the latest eligible ciphertext is needed for each account in a single-month view. */
    public function latestValuation(UserId $owner, Month $at): Valuation
    {
        $values = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT s.account_id, s.month, s.ciphertext FROM accounts a
             JOIN statements s ON s.user_id = a.user_id AND s.account_id = a.id AND s.month = (
                 SELECT MAX(h.month) FROM statements h WHERE h.account_id = a.id AND h.user_id = a.user_id AND h.month <= ?
             ) WHERE a.user_id = ?',
            [$at->toString(), $owner->value],
        );
        foreach ($rows as $row) {
            $id = Row::int($row, 'account_id');
            $month = Row::string($row, 'month');
            $values[$id][$month] = $this->amount($owner, $id, $month, Row::string($row, 'ciphertext'));
        }

        return new Valuation($values);
    }

    /** @return array<string, int> YYYY-MM => cents, descending months */
    public function forAccount(UserId $owner, int $accountId): array
    {
        $result = [];
        $rows = $this->connection->fetchAllAssociative(
            'SELECT month, ciphertext FROM statements WHERE user_id = ? AND account_id = ? ORDER BY month DESC',
            [$owner->value, $accountId],
        );
        foreach ($rows as $row) {
            $month = Row::string($row, 'month');
            $result[$month] = $this->amount($owner, $accountId, $month, Row::string($row, 'ciphertext'));
        }

        return $result;
    }


    /**
     * Months with at least one balance entered, ascending. Reads the months only, in clear (never an amount): usable
     * outside a session, by the monthly reminder.
     *
     * @return list<Month>
     */
    public function months(UserId $owner): array
    {
        return array_map(
            static fn(mixed $month): Month => Month::fromString(is_string($month) ? $month : ''),
            $this->connection->fetchFirstColumn('SELECT DISTINCT month FROM statements WHERE user_id = ? ORDER BY month', [$owner->value]),
        );
    }

    /** True if at least one balance is entered for the month. Reads the months only, in clear (never an amount). */
    public function hasStatementIn(UserId $owner, Month $month): bool
    {
        return Row::int(['entered' => $this->connection->fetchOne(
            'SELECT EXISTS (SELECT 1 FROM statements WHERE user_id = ? AND month = ?)',
            [$owner->value, $month->toString()],
        )], 'entered') === 1;
    }

    public function save(UserId $owner, int $accountId, Month $month, int $cents): void
    {
        $sealed = Aead::seal($this->vault->key($owner), (string) $cents, self::context($owner, $accountId, $month->toString()));
        $this->connection->executeStatement(
            "INSERT INTO statements (account_id, user_id, month, ciphertext) VALUES (?, ?, ?, ?)
             ON CONFLICT(account_id, month) DO UPDATE SET ciphertext = excluded.ciphertext,
                 updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')
             WHERE statements.user_id = excluded.user_id",
            [$accountId, $owner->value, $month->toString(), $sealed],
        );
        // A balance saved again is no longer approximate: it has been refined (or confirmed).
        $this->connection->executeStatement(
            'DELETE FROM approximate_statements WHERE user_id = ? AND account_id = ? AND month = ?',
            [$owner->value, $accountId, $month->toString()],
        );
    }

    /** Flags a balance just saved as approximate ("to refine"), until it is saved again. */
    public function markApproximate(UserId $owner, int $accountId, Month $month): void
    {
        $this->connection->executeStatement(
            'INSERT OR IGNORE INTO approximate_statements (user_id, account_id, month)
             SELECT user_id, account_id, month FROM statements WHERE user_id = ? AND account_id = ? AND month = ?',
            [$owner->value, $accountId, $month->toString()],
        );
    }

    /**
     * Balances flagged approximate, in clear (no amount): account => (YYYY-MM => true).
     *
     * @return array<int, array<string, true>>
     */
    public function approximate(UserId $owner): array
    {
        $flags = [];
        foreach ($this->connection->fetchAllAssociative('SELECT account_id, month FROM approximate_statements WHERE user_id = ?', [$owner->value]) as $row) {
            $flags[Row::int($row, 'account_id')][Row::string($row, 'month')] = true;
        }

        return $flags;
    }

    public function delete(UserId $owner, int $accountId, Month $month): void
    {
        $this->connection->executeStatement(
            'DELETE FROM statements WHERE user_id = ? AND account_id = ? AND month = ?',
            [$owner->value, $accountId, $month->toString()],
        );
    }

    /**
     * Saves the entry of a month in a single transaction.
     * Null value = no statement for this month (the last known balance will be carried over).
     *
     * @param array<int, int|null> $values account => cents or null
     */
    public function saveMonth(UserId $owner, Month $month, array $values): void
    {
        $this->connection->transactional(function () use ($owner, $month, $values): void {
            foreach ($values as $accountId => $cents) {
                if ($cents === null) {
                    $this->delete($owner, $accountId, $month);
                } else {
                    $this->save($owner, $accountId, $month, $cents);
                }
            }
        });
    }

    /** Encrypts all the owner's statements again: the current key is already open, $newKey replaces it. */
    public function reencryptAll(UserId $owner, #[\SensitiveParameter] string $newKey): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT account_id, month, ciphertext FROM statements WHERE user_id = ?', [$owner->value]);
        foreach ($rows as $row) {
            $accountId = Row::int($row, 'account_id');
            $month = Row::string($row, 'month');
            $context = self::context($owner, $accountId, $month);
            $this->connection->executeStatement(
                'UPDATE statements SET ciphertext = ? WHERE user_id = ? AND account_id = ? AND month = ?',
                [Aead::seal($newKey, Aead::open($this->vault->key($owner), Row::string($row, 'ciphertext'), $context), $context), $owner->value, $accountId, $month],
            );
        }
    }

    private function amount(UserId $owner, int $accountId, string $month, string $ciphertext): int
    {
        return Row::int(['cents' => Aead::open($this->vault->key($owner), $ciphertext, self::context($owner, $accountId, $month))], 'cents');
    }

    private static function context(UserId $owner, int $accountId, string $month): string
    {
        return "statements:$accountId:$month:{$owner->value}:1";
    }
}
