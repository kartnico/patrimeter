<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\Game;

use Patrimeter\Domain\Game\StreakNudge;
use Patrimeter\Domain\Month;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StreakNudgeTest extends TestCase
{
    /** @return iterable<string, array{list<string>, int, int, int, bool, ?int}> */
    public static function cases(): iterable
    {
        yield 'nothing entered yet' => [[], 0, 0, 1, false, 3];
        yield 'one month: the first star at three' => [['2026-10'], 0, 1, 2, false, 3];
        yield 'two months: entering earns the first star' => [['2026-09', '2026-10'], 0, 2, 3, true, null];
        yield 'first star kept: the next one at six' => [['2026-08', '2026-09', '2026-10'], 1, 3, 4, false, 6];
        yield 'all stars kept' => [['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], 3, 13, 14, false, null];
        yield 'a star kept from a past streak: none new below it' => [['2026-09', '2026-10'], 2, 2, 3, false, 12];
    }

    /** @param list<string> $entered */
    #[DataProvider('cases')]
    public function testWhatEnteringTheMonthBrings(array $entered, int $best, int $months, int $after, bool $newStar, ?int $next): void
    {
        $nudge = StreakNudge::of(array_map(Month::fromString(...), $entered), Month::fromString('2026-11'), $best);

        self::assertSame([$months, $after, $newStar, $next], [$nudge->months, $nudge->afterEntry, $nudge->newStar, $nudge->nextStarAt]);
    }
}
