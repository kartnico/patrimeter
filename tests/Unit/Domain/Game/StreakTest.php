<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\Game;

use Patrimeter\Domain\Game\Streak;
use Patrimeter\Domain\Month;
use PHPUnit\Framework\TestCase;

/** Months in a row with balances entered, with one forgotten month forgiven per calendar year. */
final class StreakTest extends TestCase
{
    public function testMonthsInARowUpToThisMonth(): void
    {
        $streak = Streak::of(self::months('2026-08', '2026-09', '2026-10', '2026-11'), Month::fromString('2026-11'));

        self::assertSame(4, $streak->length);
        self::assertSame([], $streak->jokers);
    }

    public function testThisMonthNotEnteredYetKeepsTheStreakAlive(): void
    {
        $streak = Streak::of(self::months('2026-09', '2026-10'), Month::fromString('2026-11'));

        self::assertSame(2, $streak->length);
    }

    public function testOneForgottenMonthAYearIsForgiven(): void
    {
        // August forgotten: forgiven, it counts; April forgotten too: the same year has no joker left.
        $streak = Streak::of(self::months('2026-01', '2026-02', '2026-03', '2026-05', '2026-06', '2026-07', '2026-09', '2026-10', '2026-11'), Month::fromString('2026-11'));

        self::assertSame(7, $streak->length, 'May to November, August included');
        self::assertSame(['2026-08'], array_map(static fn(Month $month): string => $month->toString(), $streak->jokers));
        self::assertFalse($streak->jokerLeftIn(2026));
        self::assertTrue($streak->jokerLeftIn(2027));
    }

    public function testAJokerOfEachYear(): void
    {
        // November 2025 and February 2026 forgotten: one joker for each year.
        $streak = Streak::of(self::months('2025-09', '2025-10', '2025-12', '2026-01', '2026-03'), Month::fromString('2026-03'));

        self::assertSame(7, $streak->length);
        self::assertCount(2, $streak->jokers);
    }

    public function testNothingEnteredOrTwoMonthsMissing(): void
    {
        self::assertSame(0, Streak::of([], Month::fromString('2026-11'))->length);
        self::assertSame(0, Streak::of(self::months('2026-08'), Month::fromString('2026-11'))->length, 'September and October missing');
    }

    /** @return list<Month> */
    private static function months(string ...$values): array
    {
        return array_map(Month::fromString(...), array_values($values));
    }
}
