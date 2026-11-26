<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Resolves the value of the accounts month by month, carrying over the last known balance.
 * Pure calculation (no database): the statements are given as input.
 */
final class Valuation
{
    /** @var array<int, list<string>> sorted months by account */
    private array $months = [];

    /** @param array<int, array<string, int>> $statements account => (YYYY-MM => cents) */
    public function __construct(private readonly array $statements)
    {
        foreach ($statements as $accountId => $byMonth) {
            $keys = array_map('strval', array_keys($byMonth));
            sort($keys, SORT_STRING);
            $this->months[$accountId] = $keys;
        }
    }

    /**
     * Value of the account in the given month: the month's statement if it exists, else the last earlier
     * statement (carried-over value), else null.
     */
    public function valueAt(int $accountId, Month $month): ?AccountValue
    {
        $months = $this->months[$accountId] ?? [];
        $target = $month->toString();

        // Binary search of the last month <= target.
        $lo = 0;
        $hi = count($months) - 1;
        $found = null;
        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            if (strcmp($months[$mid], $target) <= 0) {
                $found = $months[$mid];
                $lo = $mid + 1;
            } else {
                $hi = $mid - 1;
            }
        }
        if ($found === null) {
            return null;
        }

        return new AccountValue($this->statements[$accountId][$found], Month::fromString($found), $found !== $target);
    }

    /** First month for which at least one statement exists. */
    public function firstMonth(): ?Month
    {
        $first = null;
        foreach ($this->months as $months) {
            if ($months !== [] && ($first === null || strcmp($months[0], $first) < 0)) {
                $first = $months[0];
            }
        }

        return $first === null ? null : Month::fromString($first);
    }
}
