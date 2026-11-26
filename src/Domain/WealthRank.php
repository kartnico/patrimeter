<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** Position of a net worth among French households (WealthDistribution). */
final readonly class WealthRank
{
    public function __construct(
        /** Share of households with a lower net worth, in percent (0 to 99). */
        public float $percentile,
    ) {}

    /** Percentage rounded to the unit, bounded from 1 to 99 so as never to announce "0 %" nor "100 %". */
    public function percent(): int
    {
        return max(1, min(99, (int) round($this->percentile)));
    }

    /** Decile from 1 (the 10 % least well-off) to 10 (the 10 % best-off). */
    public function decile(): int
    {
        return min(10, intdiv((int) floor($this->percentile), 10) + 1);
    }

    /** Among the best-off 1 % of households (beyond the last published threshold). */
    public function isTopPercent(): bool
    {
        return $this->percentile >= 99.0;
    }

    /** Below the first published decile. */
    public function isBottomDecile(): bool
    {
        return $this->percentile < 10.0;
    }
}
