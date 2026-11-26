<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

use Patrimeter\Domain\Month;

/**
 * Complete budget of a household, in memory (pure): household, categories, items and amounts saved month after month.
 * Used for the budget of a member imported into a family account, and for bringing them together (BudgetBook::family()).
 */
final class BudgetBook
{
    /** @var list<MonthlyBudget> in ascending months */
    public readonly array $months;

    /**
     * @param list<BudgetCategory> $categories
     * @param list<BudgetLine>     $lines
     * @param list<MonthlyBudget>  $months
     */
    public function __construct(
        public readonly Household $household,
        public readonly array $categories,
        public readonly array $lines,
        array $months,
    ) {
        usort($months, static fn(MonthlyBudget $a, MonthlyBudget $b): int => $a->month->compare($b->month));
        $this->months = $months;
    }

    /** Amounts in force for the month: those of the month, else of the last month saved before it, else null. */
    public function budgetAt(Month $month): ?MonthlyBudget
    {
        $found = null;
        foreach ($this->months as $budget) {
            if ($budget->month->compare($month) > 0) {
                break;
            }
            $found = $budget;
        }

        return $found;
    }

    /**
     * Months in a row, back from $month, whose budget in force plans savings of at least $percent % of the incomes; a
     * month without a budget, or without incomes, ends the run.
     */
    public function savingsRateMonths(Month $month, int $percent): int
    {
        $first = $this->firstMonth();
        $count = 0;
        for ($candidate = $month; $first !== null && $candidate->compare($first) >= 0; $candidate = $candidate->previous()) {
            $budget = $this->budgetAt($candidate);
            $rate = $budget === null ? null : (new BudgetOverview($this->household, $budget, $this->categories, $this->lines))->savingsRate();
            if ($rate === null || $rate < $percent) {
                break;
            }
            ++$count;
        }

        return $count;
    }

    public function firstMonth(): ?Month
    {
        return ($this->months[0] ?? null)?->month;
    }

    /**
     * Family budget for a month: the sum of its members' budgets (two at most). Each member becomes an adult, under
     * their name, with their incomes (those of all the adults of their budget) and all their categories, which are
     * their own: the family has no joint account, each member having entered their share. Children with the same first
     * name are merged; the emergency savings target is the members' highest. Category and item identifiers must differ
     * from one member to another. Null if no member has a budget for that month.
     *
     * @param list<array{string, self}> $members name of each member and their budget, in the members' order
     */
    public static function family(array $members, Month $month): ?self
    {
        if ($members === [] || count($members) > Household::SECOND_ADULT) {
            return null;
        }
        $adults = [];
        $children = [];
        $childIds = [];
        $precaution = 0;
        $categories = [];
        $lines = [];
        $incomes = [];
        $childIncomes = [];
        $amounts = [];
        $latest = null;
        foreach ($members as $index => [$name, $book]) {
            $adult = $index + 1;
            $adults[$adult] = $name;
            $precaution = max($precaution, $book->household->precautionMonths);
            $ownChildren = [];
            foreach ($book->household->children as $id => $child) {
                $key = mb_strtolower(trim($child));
                if (!isset($childIds[$key]) && count($children) < Household::MAX_CHILDREN) {
                    $childIds[$key] = count($children) + 1;
                    $children[$childIds[$key]] = $child;
                }
                $ownChildren[$id] = $childIds[$key] ?? null;
            }
            $kept = [];
            foreach ($book->categories as $category) {
                // A category of an adult missing from the member's household is ignored, as in their budget.
                if ($category->adult === null || $book->household->hasAdult($category->adult)) {
                    $categories[] = new BudgetCategory($category->id, $category->name, $category->group, $adult);
                    $kept[$category->id] = true;
                }
            }
            foreach ($book->lines as $line) {
                if (isset($kept[$line->categoryId])) {
                    $lines[] = new BudgetLine($line->id, $line->categoryId, $line->name, $line->childId === null ? null : ($ownChildren[$line->childId] ?? null), $line->accountId);
                }
            }
            $budget = $book->budgetAt($month);
            $incomes[$adult] = 0;
            if ($budget === null) {
                continue;
            }
            foreach ($book->household->adultIds() as $own) {
                $incomes[$adult] += $budget->income($own);
            }
            // Incomes of a working child, under the child of the family (the same first name in two members' budgets).
            foreach ($ownChildren as $own => $familyChild) {
                if ($familyChild !== null && $budget->childIncome($own) > 0) {
                    $childIncomes[$familyChild] = ($childIncomes[$familyChild] ?? 0) + $budget->childIncome($own);
                }
            }
            foreach ($budget->amounts as $line => $cents) {
                $amounts[$line] = $cents;
            }
            $latest = $latest === null || $budget->month->compare($latest) > 0 ? $budget->month : $latest;
        }
        if ($latest === null) {
            return null;
        }
        $household = new Household($adults, $children, $precaution, count($children) + 1);
        $cap = static fn(int $cents): int => min($cents, MonthlyBudget::MAX_CENTS);

        return new self($household, $categories, $lines, [new MonthlyBudget($latest, array_map($cap, $incomes), $amounts, childIncomes: array_map($cap, $childIncomes))]);
    }

    /** First month of the family budget: the earliest of its members'. */
    public static function firstMonthOf(self ...$books): ?Month
    {
        $first = null;
        foreach ($books as $book) {
            $month = $book->firstMonth();
            if ($month !== null && ($first === null || $month->compare($first) < 0)) {
                $first = $month;
            }
        }

        return $first;
    }
}
