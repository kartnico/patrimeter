<?php

declare(strict_types=1);

namespace Patrimeter\Application\Budget;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Budget\PrecautionCoverage;
use Patrimeter\Domain\Budget\SavingsTrack;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthSnapshot;

/**
 * Budget of a month ready to display: the household, its structure, the amounts in force (those of the month or
 * carried over) and their calculation, set against the net worth of the same month (net worth, emergency savings,
 * savings accounts).
 */
final class BudgetView
{
    /**
     * @param list<BudgetCategory> $categories
     * @param list<BudgetLine>     $lines
     * @param list<SavingsTrack>   $savings
     * @param array<int, Account>  $accounts   the user's accounts, by identifier
     * @param int|null             $netWorthChange change in the net worth since the previous month (null without data)
     */
    public function __construct(
        public readonly Month $month,
        public readonly ?Household $household,
        public readonly array $categories = [],
        public readonly array $lines = [],
        public readonly ?MonthlyBudget $budget = null,
        public readonly ?BudgetOverview $overview = null,
        public readonly ?NetWorthSnapshot $netWorth = null,
        public readonly ?PrecautionCoverage $precaution = null,
        public readonly array $savings = [],
        public readonly array $accounts = [],
        public readonly ?Month $firstMonth = null,
        public readonly ?int $netWorthChange = null,
    ) {}

    /** Largest amount of the savings payments (planned or change in balance): common scale of their bars. */
    public function savingsScale(): int
    {
        $scale = 0;
        foreach ($this->savings as $track) {
            $scale = max($scale, $track->planned, abs($track->change ?? 0));
        }

        return $scale;
    }

    /** Amounts saved for an earlier month and carried over to this one. */
    public function isCarried(): bool
    {
        return $this->budget !== null && !$this->budget->month->equals($this->month);
    }
}
