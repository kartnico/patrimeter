<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Domain\Month;
use Symfony\Component\HttpFoundation\Request;

/** Reading of the month asked for in the address (?mois=YYYY-MM). */
final class MonthQuery
{
    /** Month asked for, or $default if it is missing or invalid. */
    public static function from(Request $request, Month $default): Month
    {
        return Month::tryFromString($request->query->getString('mois')) ?? $default;
    }
}
