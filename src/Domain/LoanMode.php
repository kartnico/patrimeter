<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** How to count a loan tied to a property held in part. */
enum LoanMode: string
{
    /** The amount entered is the whole loan, counted according to the property's share. */
    case Prorata = 'prorata';
    /** The amount entered is one's own share, counted as is. */
    case Own = 'own';

    /** @return list<string> values accepted as input (Choice constraint) */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
