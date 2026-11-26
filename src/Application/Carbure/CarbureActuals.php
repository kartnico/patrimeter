<?php

declare(strict_types=1);

namespace Patrimeter\Application\Carbure;

use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetOverview;

/**
 * Actual spending of a month read from Carbure: total per budget category (according to the mapping), and spending of
 * the Carbure categories without a mapping (neither matched nor ignored).
 */
final class CarbureActuals
{
    /**
     * @param array<int, int>                         $byCategory budget category => cents spent
     * @param list<array{name: string, cents: int}> $unmatched
     */
    public function __construct(
        public readonly array $byCategory,
        public readonly array $unmatched = [],
    ) {}

    public function of(int $category): ?int
    {
        return $this->byCategory[$category] ?? null;
    }

    public function unmatchedTotal(): int
    {
        return array_sum(array_column($this->unmatched, 'cents'));
    }

    /**
     * Planned and actual amounts of the budget categories matched with a Carbure category, in the budget's order, and
     * their totals.
     *
     * @return array{rows: list<array{category: BudgetCategory, planned: int, actual: int}>, planned: int, actual: int}
     */
    public function compare(BudgetOverview $overview): array
    {
        $rows = [];
        $planned = 0;
        $actual = 0;
        foreach ($overview->categories as $category) {
            $spent = $this->of($category->id);
            if ($spent === null) {
                continue;
            }
            $rows[] = ['category' => $category, 'planned' => $overview->categoryTotal($category->id), 'actual' => $spent];
            $planned += $overview->categoryTotal($category->id);
            $actual += $spent;
        }

        return ['rows' => $rows, 'planned' => $planned, 'actual' => $actual];
    }
}
