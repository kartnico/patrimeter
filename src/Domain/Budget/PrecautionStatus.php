<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

/**
 * Status of the emergency savings, relative to the household's monthly spending (PrecautionCoverage::status()).
 */
enum PrecautionStatus: string
{
    /** Spending of the month unknown: no budget for that month, or a budget without spending. */
    case Unknown = 'unknown';
    /** Less than one month of spending set aside. */
    case Critical = 'critical';
    /** At least one month, but below the household's target. */
    case Low = 'low';
    /** Target reached, up to twice the target. */
    case Reached = 'reached';
    /** More than twice the target: the surplus could be invested higher up in the pyramid. */
    case Surplus = 'surplus';
    /** At least one month of spending, the household having set no target (0 months). */
    case NoTarget = 'no_target';

    /** Situation to correct: less than one month of spending, or below the target. */
    public function isAlert(): bool
    {
        return $this === self::Critical || $this === self::Low;
    }
}
