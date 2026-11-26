<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Trend of a monthly value over a period of 3, 6 or 12 months, compared with the period just before (pure).
 * - Net worth: change over the period (value of the month − value N months earlier), and the same for the previous
 *   period;
 * - budget: average of each month of the period (spending, savings), and the same for the previous period.
 * Null where a month has no value.
 */
final class Trend
{
    public const array PERIODS = [3, 6, 12];

    private function __construct(
        public readonly int $months,
        /** Change over the period, or average per month (budget). */
        public readonly ?int $value,
        /** Same for the previous period. */
        public readonly ?int $previous,
    ) {}

    /**
     * Change of a value over each period, up to $month.
     *
     * @param callable(Month): ?int $value value of a month, null without data
     *
     * @return list<self>
     */
    public static function changes(callable $value, Month $month): array
    {
        $trends = [];
        foreach (self::PERIODS as $months) {
            $now = $value($month);
            $start = $value($month->addMonths(-$months));
            $before = $value($month->addMonths(-2 * $months));
            $trends[] = new self(
                $months,
                $now === null || $start === null ? null : $now - $start,
                $start === null || $before === null ? null : $start - $before,
            );
        }

        return $trends;
    }

    /**
     * Average of a value over the months of each period, up to $month.
     *
     * @param callable(Month): ?int $value value of a month, null without data
     *
     * @return list<self>
     */
    public static function averages(callable $value, Month $month): array
    {
        $trends = [];
        foreach (self::PERIODS as $months) {
            $trends[] = new self(
                $months,
                self::average($value, $month->addMonths(1 - $months), $month),
                self::average($value, $month->addMonths(1 - 2 * $months), $month->addMonths(-$months)),
            );
        }

        return $trends;
    }

    /** Change per month over the period (net worth), rounded to the cent. */
    public function perMonth(): ?int
    {
        return $this->value === null ? null : (int) round($this->value / $this->months);
    }

    /** Difference with the previous period, or null. */
    public function difference(): ?int
    {
        return $this->value === null || $this->previous === null ? null : $this->value - $this->previous;
    }

    /** @param callable(Month): ?int $value */
    private static function average(callable $value, Month $from, Month $to): ?int
    {
        $total = 0;
        foreach (Month::range($from, $to) as $month) {
            $cents = $value($month);
            if ($cents === null) {
                return null;
            }
            $total += $cents;
        }

        return (int) round($total / ($from->monthsUntil($to) + 1));
    }
}
