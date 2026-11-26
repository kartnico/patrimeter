<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Game;

use Patrimeter\Domain\Month;

/**
 * What the challenges measure for a user in a month, read from the data already kept (statements, budget, goals,
 * passkeys). Habits and safety only: neither a return, nor the share of a level, nor a product.
 */
final class GameFacts
{
    /**
     * @param list<Month> $enteredMonths            months with at least one balance entered (not carried), ascending
     * @param int         $completeMonths           months where every account open in that month has its balance entered
     * @param int         $accountCount             accounts and loans other than the properties
     * @param int|null    $precautionMonths         whole months of the budget's spending covered by the emergency savings;
     *                                              null without a budget
     * @param int         $precautionTarget         months of spending aimed at (tax household setting)
     * @param int         $consumerCreditFreeMonths months in a row, up to this one, without any consumer loan left to repay
     * @param int         $budgetMonths             months with a budget entered
     * @param int|null    $goalPercent              percentage reached by the most advanced savings goal; null without a goal
     * @param int|null    $savingsRateTarget        savings rate aimed at, in percent, chosen by the user; null: not chosen
     * @param int         $savingsRateMonths        months in a row whose budget plans at least that savings rate
     */
    public function __construct(
        public readonly Month $month,
        public readonly array $enteredMonths = [],
        public readonly int $completeMonths = 0,
        public readonly int $accountCount = 0,
        public readonly bool $hasHousehold = false,
        public readonly bool $hasProperty = false,
        public readonly ?int $precautionMonths = null,
        public readonly int $precautionTarget = 6,
        public readonly int $consumerCreditFreeMonths = 0,
        public readonly int $budgetMonths = 0,
        public readonly int $goalCount = 0,
        public readonly ?string $goalName = null,
        public readonly ?int $goalPercent = null,
        public readonly bool $goalReached = false,
        public readonly int $passkeys = 0,
        public readonly ?int $savingsRateTarget = null,
        public readonly int $savingsRateMonths = 0,
    ) {}

    public function hasEntered(Month $month): bool
    {
        foreach ($this->enteredMonths as $entered) {
            if ($entered->equals($month)) {
                return true;
            }
        }

        return false;
    }

    public function streak(): Streak
    {
        return Streak::of($this->enteredMonths, $this->month);
    }
}
