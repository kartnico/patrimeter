<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Goal;

use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthSnapshot;

/**
 * Savings goal: reach a target amount by a target month, measured on the net worth, one pyramid level or chosen
 * accounts. The starting point (month and value of the perimeter when the goal was created) fixes the trajectory.
 */
final class SavingsGoal
{
    public const int NAME_MAX = 60;
    /** Largest target amount: 100 million euros. */
    public const int TARGET_MAX = 10_000_000_000;
    /** Largest target in months of spending. */
    public const int SPENDING_MONTHS_MAX = 36;

    /** @param list<int> $accountIds accounts measured (scope Accounts only) */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $targetCents,
        public readonly Month $targetMonth,
        public readonly GoalScope $scope,
        public readonly ?Level $level,
        public readonly array $accountIds,
        public readonly Month $startMonth,
        public readonly int $startCents,
        /** Target in months of spending of the household budget (the amount then follows the budget), or null. */
        public readonly ?int $spendingMonths = null,
    ) {}

    public static function isValidName(string $name): bool
    {
        $length = mb_strlen($name);

        return $length >= 1 && $length <= self::NAME_MAX && preg_match('/[\x00-\x1F\x7F]/u', $name) !== 1;
    }

    /** Value of the goal's perimeter in a month (values retained by the net worth: shares and linked loans applied). */
    public function valueIn(NetWorthSnapshot $snapshot): int
    {
        return match ($this->scope) {
            GoalScope::NetWorth => $snapshot->net(),
            GoalScope::Level => $this->level === null ? 0 : $snapshot->level($this->level)->net(),
            GoalScope::Accounts => $snapshot->accountsNet($this->accountIds),
        };
    }

    /** Same goal under another identifier (creation: the row is reserved first). */
    public function withId(int $id): self
    {
        return new self($id, $this->name, $this->targetCents, $this->targetMonth, $this->scope, $this->level, $this->accountIds, $this->startMonth, $this->startCents, $this->spendingMonths);
    }

    /** Same goal with another target amount (target in months of spending, for a given month). */
    public function withTarget(int $cents): self
    {
        return new self($this->id, $this->name, $cents, $this->targetMonth, $this->scope, $this->level, $this->accountIds, $this->startMonth, $this->startCents, $this->spendingMonths);
    }
}
