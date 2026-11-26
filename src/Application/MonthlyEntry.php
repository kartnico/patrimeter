<?php

declare(strict_types=1);

namespace Patrimeter\Application;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Account;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;

/**
 * Monthly entry: saving the balances, and carrying into a past month the accounts opened after it. An account created
 * in November, whose October balance is entered by copying November's, already existed in October: its opening date
 * is moved back to 1 October, in the same transaction as the balances.
 */
final class MonthlyEntry
{
    public function __construct(
        private readonly Connection $connection,
        private readonly AccountRepository $accounts,
        private readonly StatementRepository $statements,
    ) {}

    /**
     * Accounts opened in $source but after $month: those that a copy from $source to $month can carry over.
     *
     * @return array<int, Account> indexed by identifier
     */
    public function openedAfter(UserId $owner, Month $month, Month $source): array
    {
        $accounts = [];
        foreach ($this->accounts->activeIn($owner, $source) as $account) {
            if (Month::fromDate($account->openedOn)->compare($month) > 0) {
                $accounts[$account->id] = $account;
            }
        }

        return $accounts;
    }

    /**
     * @param array<int, int|null> $values  balance per account (null: statement of the month removed)
     * @param list<int>            $advance accounts whose opening moves to the 1st of the month
     */
    public function save(UserId $owner, Month $month, array $values, array $advance): void
    {
        $this->connection->transactional(function () use ($owner, $month, $values, $advance): void {
            foreach ($advance as $id) {
                $this->accounts->advanceOpening($owner, $id, $month->toString() . '-01');
            }
            $this->statements->saveMonth($owner, $month, $values);
        });
    }
}
