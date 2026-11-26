<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

/**
 * Emergency savings of the month (pyramid level), relative to the household's monthly spending (needs and wants,
 * without savings): number of months covered, and gap with the household's target in months of spending (surplus if
 * positive, shortfall if negative). Zero spending means it is unknown (no budget).
 */
final class PrecautionCoverage
{
    /** Beyond this multiple of the target, the emergency savings are deemed excessive. */
    public const int SURPLUS_FACTOR = 2;

    public function __construct(
        public readonly int $amount,
        public readonly int $monthlyExpenses,
        public readonly int $targetMonths,
    ) {}

    /** Months of spending covered; null without spending. */
    public function months(): ?float
    {
        return $this->monthlyExpenses > 0 ? $this->amount / $this->monthlyExpenses : null;
    }

    public function hasTarget(): bool
    {
        return $this->targetMonths > 0 && $this->monthlyExpenses > 0;
    }

    /** Amount of the target: number of months aimed at multiplied by the monthly spending. */
    public function target(): int
    {
        return $this->targetMonths * $this->monthlyExpenses;
    }

    /** Gap with the target: surplus (positive) or shortfall (negative). */
    public function gap(): int
    {
        return $this->amount - $this->target();
    }

    /** Status, from the most urgent (less than one month of spending) to the surplus (more than twice the target). */
    public function status(): PrecautionStatus
    {
        return match (true) {
            $this->monthlyExpenses <= 0 => PrecautionStatus::Unknown,
            $this->amount < $this->monthlyExpenses => PrecautionStatus::Critical,
            $this->targetMonths <= 0 => PrecautionStatus::NoTarget,
            $this->amount < $this->target() => PrecautionStatus::Low,
            $this->amount > self::SURPLUS_FACTOR * $this->target() => PrecautionStatus::Surplus,
            default => PrecautionStatus::Reached,
        };
    }
}
