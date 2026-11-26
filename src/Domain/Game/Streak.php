<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Game;

use Patrimeter\Domain\Month;

/**
 * Months in a row with balances entered, up to this month (or up to the previous one while this month is not entered
 * yet: the streak is still alive). One missed month per calendar year is forgiven (the joker), when the month before it
 * was entered: it counts in the streak, so that a holiday month does not wipe out a year of habit.
 */
final class Streak
{
    /** @param list<Month> $jokers months forgiven, from the most recent */
    private function __construct(
        public readonly int $length,
        public readonly array $jokers,
    ) {}

    /** @param list<Month> $entered months with balances entered */
    public static function of(array $entered, Month $current): self
    {
        $months = [];
        foreach ($entered as $month) {
            $months[$month->toString()] = true;
        }
        $isEntered = static fn(Month $month): bool => isset($months[$month->toString()]);

        $month = $isEntered($current) ? $current : $current->previous();
        $length = 0;
        $jokers = [];
        $years = [];
        while (true) {
            if ($isEntered($month)) {
                ++$length;
            } elseif (!isset($years[$month->year]) && $isEntered($month->previous())) {
                // A single gap after an entered month: forgiven once a calendar year.
                $years[$month->year] = true;
                $jokers[] = $month;
                ++$length;
            } else {
                break;
            }
            $month = $month->previous();
        }

        return new self($length, $jokers);
    }

    /** Whether the joker of this calendar year is still available. */
    public function jokerLeftIn(int $year): bool
    {
        foreach ($this->jokers as $joker) {
            if ($joker->year === $year) {
                return false;
            }
        }

        return true;
    }
}
