<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Budget;

/**
 * Budget of a month, calculated (pure, no database):
 * - total of each category, and of each group of the 50/30/20 rule for the whole household;
 * - transfer to the joint account (total of the shared categories) and each adult's share;
 * - each adult's own spending and savings, and what they have left;
 * - the household's remaining money and the monthly cost of each child.
 *
 * A category of an adult no longer in the household is ignored, like a child removed from the household.
 */
final class BudgetOverview
{
    /** @var list<BudgetCategory> categories kept, in the order received */
    public readonly array $categories;
    /** @var array<int, list<BudgetLine>> category => items */
    public readonly array $linesByCategory;
    /** @var array<int, int> category => total */
    public readonly array $categoryTotals;
    /** @var array<string, int> group (BudgetGroup) => household total */
    public readonly array $groupTotals;
    /** Net incomes of the household. */
    public readonly int $income;
    /** Transfer to the joint account: total of the shared categories. */
    public readonly int $jointTotal;
    /** Share of the first adult in this transfer, in millionths. */
    public readonly int $firstAdultShare;
    /** @var array<int, int> adult => transfer to the joint account */
    public readonly array $jointParts;
    /** @var array<int, int> adult => total of their own categories */
    public readonly array $personalTotals;
    /** @var array<int, int> child => total of the items tied to them */
    public readonly array $childCosts;
    /** At least one budget line is attached to a child of the household: the cost of the children is shown. */
    public readonly bool $hasChildLines;

    /**
     * @param list<BudgetCategory> $categories
     * @param list<BudgetLine>     $lines
     */
    public function __construct(
        public readonly Household $household,
        public readonly MonthlyBudget $budget,
        array $categories,
        array $lines,
    ) {
        $this->categories = array_values(array_filter(
            $categories,
            static fn(BudgetCategory $category): bool => $category->adult === null || $household->hasAdult($category->adult),
        ));
        $byCategory = [];
        foreach ($this->categories as $category) {
            $byCategory[$category->id] = [];
        }
        foreach ($lines as $line) {
            if (isset($byCategory[$line->categoryId])) {
                $byCategory[$line->categoryId][] = $line;
            }
        }
        $this->linesByCategory = $byCategory;

        $categoryTotals = [];
        $groupTotals = array_fill_keys(BudgetGroup::values(), 0);
        $jointTotal = 0;
        $personal = array_fill_keys($household->adultIds(), 0);
        $children = array_fill_keys(array_keys($household->children), 0);
        $hasChildLines = false;
        foreach ($this->categories as $category) {
            $total = 0;
            foreach ($byCategory[$category->id] as $line) {
                $amount = $budget->amount($line->id);
                $total += $amount;
                if ($line->childId !== null && isset($children[$line->childId])) {
                    $children[$line->childId] += $amount;
                    $hasChildLines = true;
                }
            }
            $categoryTotals[$category->id] = $total;
            $groupTotals[$category->group->value] += $total;
            if ($category->adult === null) {
                $jointTotal += $total;
            } else {
                $personal[$category->adult] += $total;
            }
        }
        $income = 0;
        foreach ($household->adultIds() as $adult) {
            $income += $budget->income($adult);
        }
        // Incomes of the children still in the household (of working age when they were entered).
        foreach (array_keys($household->children) as $child) {
            $income += $budget->childIncome($child);
        }

        $this->categoryTotals = $categoryTotals;
        $this->groupTotals = $groupTotals;
        $this->income = $income;
        $this->jointTotal = $jointTotal;
        $this->firstAdultShare = JointAccount::firstShare($budget, $household);
        $this->jointParts = JointAccount::split($jointTotal, $this->firstAdultShare, $household);
        $this->personalTotals = $personal;
        $this->childCosts = $children;
        $this->hasChildLines = $hasChildLines;
    }

    public function groupTotal(BudgetGroup $group): int
    {
        return $this->groupTotals[$group->value];
    }

    /** Spending and savings of the whole household. */
    public function spending(): int
    {
        return array_sum($this->groupTotals);
    }

    /** Household spending, without savings (needs and wants). */
    public function expenses(): int
    {
        return $this->groupTotal(BudgetGroup::Needs) + $this->groupTotal(BudgetGroup::Wants);
    }

    /** Savings planned by the whole household. */
    public function savings(): int
    {
        return $this->groupTotal(BudgetGroup::Savings);
    }

    /** Incomes left after spending and savings; negative if the budget exceeds them. */
    public function available(): int
    {
        return $this->income - $this->spending();
    }

    /** What the adult has left after their transfer to the joint account and their own categories. */
    public function remainder(int $adult): int
    {
        return $this->budget->income($adult) - ($this->jointParts[$adult] ?? 0) - ($this->personalTotals[$adult] ?? 0);
    }

    public function categoryTotal(int $category): int
    {
        return $this->categoryTotals[$category] ?? 0;
    }

    /** @return list<BudgetLine> */
    public function lines(int $category): array
    {
        return $this->linesByCategory[$category] ?? [];
    }

    /**
     * Shared categories ($adult null) or those of one adult.
     *
     * @return list<BudgetCategory>
     */
    public function categoriesOf(?int $adult): array
    {
        return array_values(array_filter($this->categories, static fn(BudgetCategory $category): bool => $category->adult === $adult));
    }

    /** Amount relative to the household's incomes, in percent; null without incomes. */
    public function shareOfIncome(int $cents): ?float
    {
        return $this->income > 0 ? (float) $cents * 100.0 / (float) $this->income : null;
    }

    /** Savings rate: the planned savings relative to incomes. */
    public function savingsRate(): ?float
    {
        return $this->shareOfIncome($this->savings());
    }

    /** Possible savings rate: the planned savings and the remaining money, relative to incomes. */
    public function possibleSavingsRate(): ?float
    {
        return $this->shareOfIncome($this->savings() + max(0, $this->available()));
    }
}
