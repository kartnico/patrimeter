<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Valuation;

/**
 * Savings item tied to an account: payment planned in the month's budget, against the change in the account's balance
 * over that month (statement of the month minus value of the previous month). The change stays unknown until the
 * month's statement is entered; an account without a value the previous month starts from zero. It also includes
 * interest, capital gains and withdrawals: it is a guide, not a record of payments.
 */
final class SavingsTrack
{
    public function __construct(
        public readonly BudgetLine $line,
        public readonly Account $account,
        public readonly int $planned,
        public readonly ?int $change,
    ) {}

    public static function of(BudgetLine $line, Account $account, int $planned, Valuation $valuation, Month $month): self
    {
        $current = $valuation->valueAt($account->id, $month);
        if ($current === null || $current->carried) {
            return new self($line, $account, $planned, null);
        }
        $previous = $valuation->valueAt($account->id, $month->previous());

        return new self($line, $account, $planned, $account->signed($current->cents) - $account->signed($previous->cents ?? 0));
    }

    /** Gap between the change in the balance and the planned payment; null while the month's statement is missing. */
    public function difference(): ?int
    {
        return $this->change === null ? null : $this->change - $this->planned;
    }

    public function status(): SavingsStatus
    {
        return match (true) {
            $this->change === null => SavingsStatus::Unknown,
            $this->change < 0 => SavingsStatus::Decrease,
            $this->change < $this->planned => SavingsStatus::Below,
            default => SavingsStatus::Reached,
        };
    }
}
