<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Game;

use Patrimeter\Domain\Month;

/**
 * What entering the balances of a month would bring to the entry streak: for the monthly reminder email, written from
 * the months entered alone (never an amount). A star is new only above the best ones already kept.
 */
final class StreakNudge
{
    private function __construct(
        /** Months in a row so far, up to the month before (the month itself is not entered yet). */
        public readonly int $months,
        /** Months in a row once the month is entered. */
        public readonly int $afterEntry,
        /** Entering the month earns a star of the "Série de saisies" challenge. */
        public readonly bool $newStar,
        /** Months in a row needed for the next star, if it is not the one earned now; null once all are earned. */
        public readonly ?int $nextStarAt,
    ) {}

    /**
     * @param list<Month> $entered   months with balances entered, before $month
     * @param int         $bestStars stars of the "Série de saisies" challenge already kept
     */
    public static function of(array $entered, Month $month, int $bestStars): self
    {
        $before = Challenge::Streak->evaluate(new GameFacts($month, $entered));
        $after = Challenge::Streak->evaluate(new GameFacts($month, [...$entered, $month]));
        $newStar = $after->stars() > $bestStars;

        return new self(
            $before->value,
            $after->value,
            $newStar,
            $newStar ? null : ($after->thresholds[$bestStars] ?? null),
        );
    }
}
