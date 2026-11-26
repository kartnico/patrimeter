<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

/**
 * Group of a budget category, in the sense of the 50/30/20 rule: needs (50 % of incomes), wants (30 %) and
 * savings (20 %).
 */
enum BudgetGroup: string
{
    case Needs = 'needs';
    case Wants = 'wants';
    case Savings = 'savings';

    public function label(): string
    {
        return match ($this) {
            self::Needs => 'Besoins',
            self::Wants => 'Envies',
            self::Savings => 'Épargne',
        };
    }

    /** Target share of the household's incomes, in percent (50/30/20 rule). */
    public function target(): int
    {
        return match ($this) {
            self::Needs => 50,
            self::Wants => 30,
            self::Savings => 20,
        };
    }

    /** @return list<string> values accepted as input */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
