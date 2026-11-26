<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Month;
use Patrimeter\Domain\Trend;
use PHPUnit\Framework\TestCase;

/** Trends over 3, 6 and 12 months, compared with the previous period. */
final class TrendTest extends TestCase
{
    public function testChangeOverEachPeriodAndThePreviousOne(): void
    {
        // Value growing by 100 € a month since 2025-01 (index 0), then 200 € a month from 2026-06.
        $value = static function (Month $month): ?int {
            $index = Month::fromString('2025-01')->monthsUntil($month);
            if ($index < 0) {
                return null;
            }

            return $index <= 17 ? $index * 10_000 : 170_000 + ($index - 17) * 20_000;
        };
        [$three, $six, $twelve] = Trend::changes($value, Month::fromString('2026-11'));

        self::assertSame([3, 60_000, 50_000], [$three->months, $three->value, $three->previous]);
        self::assertSame(20_000, $three->perMonth());
        self::assertSame(10_000, $three->difference());
        self::assertSame(110_000, $six->value);
        self::assertSame(12, $twelve->months);
        self::assertSame(170_000, $twelve->value);
        self::assertNull($twelve->previous, 'no value 24 months earlier');
        self::assertNull($twelve->difference());
    }

    public function testAverageOverEachPeriod(): void
    {
        $value = static fn(Month $month): int => $month->compare(Month::fromString('2026-09')) >= 0 ? 300_000 : 200_000;
        [$three, $six] = Trend::averages($value, Month::fromString('2026-11'));

        self::assertSame(300_000, $three->value);
        self::assertSame(200_000, $three->previous);
        self::assertSame(250_000, $six->value);
        self::assertSame(100_000, $three->difference());

        [$missing] = Trend::averages(static fn(Month $month): ?int => null, Month::fromString('2026-11'));
        self::assertNull($missing->value);
        self::assertNull($missing->perMonth());
    }
}
