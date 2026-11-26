<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** Validation of the dates entered (YYYY-MM-DD). */
final class DateInput
{
    public static function isValid(string $date): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\z/', $date, $m) !== 1) {
            return false;
        }
        $year = (int) $m[1];

        return $year >= 1900 && $year <= 2999 && checkdate((int) $m[2], (int) $m[3], $year);
    }
}
