<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Distribution of households' net worth in France, published by Insee: position of a net worth among that of
 * French households.
 *
 * Source: Insee, "Histoire de vie et Patrimoine" survey 2023-2024, "Distribution du patrimoine des ménages
 * début 2024" (published on 7 April 2026), France excluding Mayotte, households living in ordinary dwellings. Survey
 * run every three years: update SOURCE and QUANTILES at each new publication.
 *
 * Between two published thresholds, the position is interpolated linearly: it is an estimate, hence a rounding to
 * the unit. Below the first decile, it starts from 0 % for a zero or negative net worth.
 */
final class WealthDistribution
{
    public const string SOURCE = 'Insee, enquête Histoire de vie et Patrimoine 2023-2024 (patrimoine net des ménages début 2024, publié le 7 avril 2026)';
    public const string SOURCE_URL = 'https://www.insee.fr/fr/statistiques/2388851';
    public const string REFERENCE = 'début 2024';

    /** Net worth thresholds, in cents, by percentile of households: 50 => median. */
    public const array QUANTILES = [
        10 => 460_000,
        20 => 1_320_000,
        30 => 3_410_000,
        40 => 7_790_000,
        50 => 14_810_000,
        60 => 22_420_000,
        70 => 31_740_000,
        80 => 45_790_000,
        90 => 75_040_000,
        95 => 115_150_000,
        99 => 272_890_000,
    ];

    /** Share of households whose net worth is below $netCents, in percent (0 to 100). */
    public static function percentile(int $netCents): float
    {
        if ($netCents <= 0) {
            return 0.0;
        }
        $lowerPercent = 0;
        $lowerCents = 0;
        foreach (self::QUANTILES as $percent => $cents) {
            if ($netCents < $cents) {
                return self::interpolate($netCents, $lowerPercent, $lowerCents, $percent, $cents);
            }
            $lowerPercent = $percent;
            $lowerCents = $cents;
        }

        // Beyond the 99th percentile, Insee publishes no threshold: stay at 99 %, "the best-off 1 %".
        return 99.0;
    }

    /** Point of the line joining ($fromCents, $fromPercent) to ($toCents, $toPercent). */
    private static function interpolate(int $netCents, int $fromPercent, int $fromCents, int $toPercent, int $toCents): float
    {
        return (float) $fromPercent + (float) ($toPercent - $fromPercent) * (float) ($netCents - $fromCents) / (float) ($toCents - $fromCents);
    }

    public static function rank(int $netCents): WealthRank
    {
        return new WealthRank(self::percentile($netCents));
    }
}
