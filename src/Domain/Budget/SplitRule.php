<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

/** Split of the transfer to the joint account between the two adults of the household. */
enum SplitRule: string
{
    /** Each pays in proportion to their incomes of the month. */
    case Income = 'income';
    /** The first adult pays the fixed share, the second the rest. */
    case Fixed = 'fixed';

    /** @return list<string> values accepted as input */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
