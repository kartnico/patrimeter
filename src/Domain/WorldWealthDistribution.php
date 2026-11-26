<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * World pyramid of net worth per adult, published by UBS: position of a net worth among that of the world's adults.
 *
 * Source: UBS, Global Wealth Report 2026 (published on 30 June 2026), net worth per adult at the end of 2025, in
 * dollars, over 56 countries that hold more than 92 % of the world's wealth (3.85 billion adults). The report only
 * publishes bands: below $10,000 (42.1 % of adults), $10,000 to $100,000 (41.1 %), $100,000 to 1 million (15.3 %),
 * more than 1 million (1.5 %), of which a little more than 7 million beyond 5 million (0.2 %). Yearly report: update
 * SOURCE, THRESHOLDS and USD_PER_EUR at each edition.
 *
 * The dollar thresholds are converted into euros at the ECB reference rate on the date of the data. Below $10,000,
 * the position is interpolated in a straight line from 0; beyond, each band covers a factor of 10 and the
 * interpolation follows the logarithm of the net worth, closer to the shape of the distribution. It is an estimate,
 * hence a rounding to the unit.
 */
final class WorldWealthDistribution
{
    public const string SOURCE = 'UBS, Global Wealth Report 2026 (patrimoine net par adulte fin 2025, publié le 30 juin 2026)';
    public const string SOURCE_URL = 'https://www.ubs.com/global/en/wealthmanagement/insights/global-wealth-report.html';
    public const string REFERENCE = 'fin 2025';

    /** ECB reference rate of 31 December 2025, in ten-thousandths of a dollar per euro ($1.1750). */
    public const int USD_PER_EUR = 11_750;

    /** Net worth thresholds per adult, in dollars, and share of adults below, in tenths of a percent. */
    public const array THRESHOLDS = [
        10_000 => 421,
        100_000 => 832,
        1_000_000 => 985,
        5_000_000 => 998,
    ];

    /**
     * Thresholds converted into euros, for display.
     *
     * @return list<array{percent: float, usd: int, cents: int}>
     */
    public static function thresholds(): array
    {
        $rows = [];
        foreach (self::THRESHOLDS as $usd => $tenths) {
            $rows[] = ['percent' => (float) $tenths / 10.0, 'usd' => $usd, 'cents' => self::toEuroCents($usd)];
        }

        return $rows;
    }

    /** Share of adults whose net worth is below $netCents (in euros), in percent (0 to 99.8). */
    public static function percentile(int $netCents): float
    {
        if ($netCents <= 0) {
            return 0.0;
        }
        $lowerPercent = 0.0;
        $lowerCents = 0;
        foreach (self::THRESHOLDS as $usd => $tenths) {
            $cents = self::toEuroCents($usd);
            $percent = (float) $tenths / 10.0;
            if ($netCents < $cents) {
                // First band in a straight line from 0, the next ones following the logarithm of the net worth.
                $position = $lowerCents === 0
                    ? (float) $netCents / (float) $cents
                    : log((float) $netCents / (float) $lowerCents) / log((float) $cents / (float) $lowerCents);

                return $lowerPercent + ($percent - $lowerPercent) * $position;
            }
            $lowerPercent = $percent;
            $lowerCents = $cents;
        }

        // Beyond the last published threshold (5 million dollars), stay at the last known share.
        return $lowerPercent;
    }

    public static function rank(int $netCents): WealthRank
    {
        return new WealthRank(self::percentile($netCents));
    }

    /** Amount in dollars converted into euro cents, rounded to the nearest cent. */
    private static function toEuroCents(int $usd): int
    {
        // $usd × 100 cents × 10,000 / USD_PER_EUR, rounded in integers.
        return intdiv($usd * 1_000_000 * 2 + self::USD_PER_EUR, self::USD_PER_EUR * 2);
    }
}
