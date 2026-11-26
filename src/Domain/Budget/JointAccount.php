<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

use Patrimeter\Domain\OwnershipShares;

/**
 * Transfer to the joint account: the total of the shared budget, split between the two adults of a couple in
 * proportion to their incomes or according to a fixed share. Shares in millionths (72.5 % = 725,000), rounded to the
 * nearest cent; the second adult pays the rest, so that the two transfers always make up the total.
 */
final class JointAccount
{
    public const int FULL = 1_000_000;
    public const int HALF = 500_000;

    /**
     * Share of the first adult: 100 % if alone; otherwise the fixed share, or their incomes relative to the couple's
     * (half if the couple declared no income).
     */
    public static function firstShare(MonthlyBudget $budget, Household $household): int
    {
        if (!$household->isCouple()) {
            return self::FULL;
        }
        if ($budget->splitRule === SplitRule::Fixed) {
            return $budget->firstAdultShare;
        }
        $first = $budget->income(Household::FIRST_ADULT);
        $total = $first + $budget->income(Household::SECOND_ADULT);
        if ($total === 0) {
            return self::HALF;
        }

        return intdiv($first * self::FULL * 2 + $total, 2 * $total);
    }

    /** @return array<int, int> adult => transfer */
    public static function split(int $total, int $firstShare, Household $household): array
    {
        if (!$household->isCouple()) {
            return [Household::FIRST_ADULT => $total];
        }
        $first = OwnershipShares::apply($total, $firstShare);

        return [Household::FIRST_ADULT => $first, Household::SECOND_ADULT => $total - $first];
    }

    /** Percentage entered ("72,5", "40 %") in millionths, from 0 to 100; null if invalid. */
    public static function parseShare(string $input): ?int
    {
        $normalized = str_replace(',', '.', trim(str_replace('%', '', $input)));
        if (preg_match('/\A(\d{1,3})(?:\.(\d{1,4}))?\z/', $normalized, $parts) !== 1) {
            return null;
        }
        $share = (int) $parts[1] * 10_000 + (int) str_pad($parts[2] ?? '', 4, '0');

        return $share <= self::FULL ? $share : null;
    }
}
