<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * What was put into or taken out of each level of the pyramid since the start of the year: net value of the month
 * minus that at the end of the previous year (December), or, without data then, of January. Balances only, so the
 * change includes gains, losses and loan repayments, not only the money saved.
 */
final class YearToDate
{
    /**
     * @param list<array{level: Level, change: int}> $levels pyramid order, from the top to the base
     */
    private function __construct(
        public readonly Month $reference,
        public readonly bool $endOfPreviousYear,
        public readonly array $levels,
        public readonly int $total,
    ) {}

    /**
     * @param callable(Month): NetWorthSnapshot $snapshotAt snapshot of a month
     */
    public static function of(NetWorthSnapshot $current, callable $snapshotAt): ?self
    {
        if (!$current->hasData()) {
            return null;
        }
        $december = $current->month->addMonths(-$current->month->month);
        $reference = $snapshotAt($december);
        $endOfPreviousYear = true;
        if (!$reference->hasData()) {
            // Data starting this year: change since January, if the month shown is later.
            $january = $december->next();
            if ($current->month->compare($january) <= 0) {
                return null;
            }
            $reference = $snapshotAt($january);
            $endOfPreviousYear = false;
            if (!$reference->hasData()) {
                return null;
            }
        }

        $levels = [];
        foreach (array_reverse(Level::ordered()) as $level) {
            $levels[] = ['level' => $level, 'change' => $current->level($level)->net() - $reference->level($level)->net()];
        }

        return new self($reference->month, $endOfPreviousYear, $levels, $current->net() - $reference->net());
    }
}
