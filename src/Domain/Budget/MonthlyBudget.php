<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

use Patrimeter\Domain\Month;

/**
 * Budget amounts saved for a month: each adult's net incomes (and those of the children of working age), split of the
 * joint account, amount of each item. They
 * apply to the following months until the next save, like a carried-over statement: $month is the month of the save,
 * not necessarily the one being looked at.
 */
final class MonthlyBudget
{
    /** Ceiling of an amount: €99,999,999.99 per month, far beyond a real budget and without overflowing the calculations. */
    public const int MAX_CENTS = 9_999_999_999;

    /**
     * @param array<int, int> $incomes adult => net incomes of the month, in cents
     * @param array<int, int> $amounts item => cents; a missing item is worth 0
     * @param array<int, int> $childIncomes child => net incomes of the month, in cents (children of working age)
     */
    public function __construct(
        public readonly Month $month,
        public readonly array $incomes = [],
        public readonly array $amounts = [],
        public readonly SplitRule $splitRule = SplitRule::Income,
        public readonly int $firstAdultShare = JointAccount::HALF,
        public readonly array $childIncomes = [],
    ) {
        foreach ([...array_values($incomes), ...array_values($amounts), ...array_values($childIncomes)] as $cents) {
            if ($cents < 0 || $cents > self::MAX_CENTS) {
                throw new \InvalidArgumentException('Montant du budget hors limites.');
            }
        }
        if ($firstAdultShare < 0 || $firstAdultShare > JointAccount::FULL) {
            throw new \InvalidArgumentException('Part du compte commun invalide.');
        }
    }

    public function income(int $adult): int
    {
        return $this->incomes[$adult] ?? 0;
    }

    public function childIncome(int $child): int
    {
        return $this->childIncomes[$child] ?? 0;
    }

    public function amount(int $line): int
    {
        return $this->amounts[$line] ?? 0;
    }
}
