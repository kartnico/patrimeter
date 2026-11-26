<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Goal;

use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthSnapshot;

/**
 * Where a savings goal stands in a month, computed from the statements only (pure):
 * - effort needed: what is still missing, divided by the months left until the target month;
 * - pace: average change of the perimeter over the last 6 months (fewer since the start of the goal), which smooths
 *   the ups and downs of the markets;
 * - status: Ahead from 120 % of the effort, On track from 100 %, Watch from 80 %, Late below (or target month passed);
 * - projected month at the current pace, and the shortfall per month.
 */
final class GoalProgress
{
    public const int PACE_MONTHS = 6;
    public const int WATCH_PERCENT = 80;
    public const int AHEAD_PERCENT = 120;

    private function __construct(
        public readonly SavingsGoal $goal,
        public readonly Month $month,
        public readonly int $value,
        public readonly GoalStatus $status,
        /** Effort still needed each month (cents), or what is missing if the target month has passed; null once reached. */
        public readonly ?int $required,
        /** Average change per month over $paceMonths months (cents), null without history. */
        public readonly ?int $pace,
        public readonly int $paceMonths,
        /** Month the target is reached at the current pace; null if reached, or never at this pace. */
        public readonly ?Month $projectedMonth,
        public readonly bool $overdue,
        /** Savings planned each month by the household budget towards the goal's accounts (cents), or null. */
        public readonly ?int $planned = null,
    ) {}

    /**
     * @param callable(Month): NetWorthSnapshot $snapshot net worth of a month
     * @param (callable(Month): ?int)|null      $target   target of the month (goal in months of spending), null: the goal's
     * @param ?int                              $planned  savings planned by the budget towards the goal's accounts
     */
    public static function of(SavingsGoal $goal, Month $month, callable $snapshot, ?callable $target = null, ?int $planned = null): self
    {
        $cents = $target === null ? null : $target($month);
        if ($cents !== null) {
            $goal = $goal->withTarget($cents);
        }
        $value = $goal->valueIn($snapshot($month));
        $missing = $goal->targetCents - $value;
        $paceMonths = max(0, min(self::PACE_MONTHS, $goal->startMonth->monthsUntil($month)));
        $pace = $paceMonths === 0 ? null : (int) round(($value - $goal->valueIn($snapshot($month->addMonths(-$paceMonths)))) / $paceMonths);
        if ($missing <= 0) {
            return new self($goal, $month, $value, GoalStatus::Reached, null, $pace, $paceMonths, null, false, $planned);
        }

        $monthsLeft = $month->monthsUntil($goal->targetMonth);
        $overdue = $monthsLeft <= 0;
        $required = $overdue ? $missing : (int) ceil($missing / $monthsLeft);
        $projected = $pace !== null && $pace > 0 ? $month->addMonths((int) ceil($missing / $pace)) : null;

        return new self($goal, $month, $value, self::status($pace, $required, $overdue), $required, $pace, $paceMonths, $projected, $overdue, $planned);
    }

    /**
     * Status of the goal in each of the last $count months up to $month, from its start month (oldest first).
     *
     * @param callable(Month): NetWorthSnapshot $snapshot
     * @param (callable(Month): ?int)|null      $target
     *
     * @return list<self>
     */
    public static function history(SavingsGoal $goal, Month $month, callable $snapshot, int $count = self::PACE_MONTHS, ?callable $target = null): array
    {
        $first = $month->addMonths(1 - $count);
        if ($first->compare($goal->startMonth) < 0) {
            $first = $goal->startMonth;
        }

        return $first->compare($month) > 0 ? [] : array_map(static fn(Month $m): self => self::of($goal, $m, $snapshot, $target), Month::range($first, $month));
    }

    /** Share of the target reached, from 0 to 100. */
    public function percent(): int
    {
        if ($this->goal->targetCents <= 0) {
            return 100;
        }

        return max(0, min(100, intdiv(max(0, $this->value) * 100, $this->goal->targetCents)));
    }

    /**
     * Share of the target that the planned trajectory (straight from the starting point to the target) gives for this
     * month, from 0 to 100: where the goal should stand today, shown on the gauge next to the share reached.
     */
    public function expectedPercent(): int
    {
        $goal = $this->goal;
        if ($goal->targetCents <= 0) {
            return 100;
        }
        $total = $goal->startMonth->monthsUntil($goal->targetMonth);
        $elapsed = max(0, min($total, $goal->startMonth->monthsUntil($this->month)));
        $planned = $total <= 0 ? $goal->targetCents : $goal->startCents + intdiv(($goal->targetCents - $goal->startCents) * $elapsed, $total);

        return max(0, min(100, intdiv(max(0, $planned) * 100, $goal->targetCents)));
    }

    /** What the pace lacks each month to keep the date (cents), 0 if it is enough. */
    public function shortfall(): int
    {
        return $this->required === null || $this->overdue ? 0 : max(0, $this->required - ($this->pace ?? 0));
    }

    /** What the budget's planned savings lack to cover the effort needed each month (cents), 0 if enough, null if unknown. */
    public function plannedShortfall(): ?int
    {
        return $this->planned === null || $this->required === null || $this->overdue ? null : max(0, $this->required - $this->planned);
    }

    /** Months between the target month and the projected month (positive: later than planned), or null. */
    public function delayMonths(): ?int
    {
        return $this->projectedMonth === null ? null : $this->goal->targetMonth->monthsUntil($this->projectedMonth);
    }

    private static function status(?int $pace, int $required, bool $overdue): GoalStatus
    {
        return match (true) {
            $overdue => GoalStatus::Late,
            $pace === null => GoalStatus::TooEarly,
            $pace * 100 >= $required * self::AHEAD_PERCENT => GoalStatus::Ahead,
            $pace >= $required => GoalStatus::OnTrack,
            $pace * 100 >= $required * self::WATCH_PERCENT => GoalStatus::Watch,
            default => GoalStatus::Late,
        };
    }
}
