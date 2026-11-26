<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** Nature of an account: what one owns (asset) or what one owes (liability, subtracted from its level). */
enum Nature: string
{
    case Asset = 'asset';
    case Liability = 'liability';

    /** @return list<string> values accepted as input (Choice constraint) */
    public static function values(): array
    {
        return array_map(static fn(self $case): string => $case->value, self::cases());
    }
}
