<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\Budget;

use Patrimeter\Domain\Budget\BudgetBook;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Month;
use PHPUnit\Framework\TestCase;

/** Budget in memory: amounts carried over from one month to the next, and sum of the budgets of a family's members. */
final class BudgetBookTest extends TestCase
{
    public function testAmountsCarryOverToLaterMonths(): void
    {
        $book = self::book(1, 'Alex', [], ['2026-09' => 100_000, '2026-06' => 90_000]);

        self::assertSame('2026-06', $book->firstMonth()?->toString());
        self::assertNull($book->budgetAt(Month::fromString('2026-05')));
        self::assertSame('2026-06', $book->budgetAt(Month::fromString('2026-08'))?->month->toString());
        self::assertSame('2026-09', $book->budgetAt(Month::fromString('2026-11'))?->month->toString());
    }

    public function testSavingsRateMonthsCountTheBudgetsInForceBackFromTheMonth(): void
    {
        // €2,000 of incomes; savings planned: €300 (15 %) from June, €100 (5 %) in September, €400 (20 %) from October.
        $savings = static fn(string $month, int $cents): MonthlyBudget => new MonthlyBudget(Month::fromString($month), [1 => 200_000], [1 => $cents]);
        $book = new BudgetBook(
            new Household([1 => 'Alex']),
            [new BudgetCategory(1, 'Épargne', BudgetGroup::Savings)],
            [new BudgetLine(1, 1, 'Livret')],
            [$savings('2026-06', 30_000), $savings('2026-09', 10_000), $savings('2026-10', 40_000)],
        );
        $november = Month::fromString('2026-11');

        self::assertSame(2, $book->savingsRateMonths($november, 15), 'October (saved) and November (carried over); September breaks the run');
        self::assertSame(2, $book->savingsRateMonths($november, 20), 'exactly the rate aimed at counts');
        self::assertSame(0, $book->savingsRateMonths($november, 25));
        self::assertSame(6, $book->savingsRateMonths($november, 5), 'back to the first budget, in June');
        self::assertSame(3, $book->savingsRateMonths(Month::fromString('2026-08'), 15), 'June to August');
        self::assertSame(0, $book->savingsRateMonths(Month::fromString('2026-05'), 5), 'before the first budget');
    }

    public function testWithoutIncomesTheSavingsRateIsUnknown(): void
    {
        $book = new BudgetBook(new Household([1 => 'Alex']), [new BudgetCategory(1, 'Épargne', BudgetGroup::Savings)], [new BudgetLine(1, 1, 'Livret')], [new MonthlyBudget(Month::fromString('2026-11'), [], [1 => 10_000])]);

        self::assertSame(0, $book->savingsRateMonths(Month::fromString('2026-11'), 5));
    }

    public function testFamilyAddsUpTheMembersAsAdults(): void
    {
        // Alex: alone, €3,000 of incomes. Sam: couple budget (€2,000 and €500), a category belonging to their second
        // adult. Léa is the child of both; Tom, Sam's only.
        $alex = self::book(1, 'Alex', ['Léa'], ['2026-10' => 300_000]);
        $sam = new BudgetBook(
            new Household([1 => 'Sam', 2 => 'Kim'], [1 => 'léa ', 2 => 'Tom'], 6, 3),
            [new BudgetCategory(2_000_001, 'Logement', BudgetGroup::Needs), new BudgetCategory(2_000_002, 'Loisirs de Kim', BudgetGroup::Wants, 2)],
            [new BudgetLine(2_000_001, 2_000_001, 'Loyer', 2), new BudgetLine(2_000_002, 2_000_002, 'Sport', 1)],
            [new MonthlyBudget(Month::fromString('2026-11'), [1 => 200_000, 2 => 50_000], [2_000_001 => 80_000, 2_000_002 => 10_000])],
        );

        $family = BudgetBook::family([['Alex', $alex], ['Sam', $sam]], Month::fromString('2026-11'));

        self::assertNotNull($family);
        self::assertSame([1 => 'Alex', 2 => 'Sam'], $family->household->adults);
        self::assertSame([1 => 'Léa', 2 => 'Tom'], $family->household->children, 'Léa réunie, Tom ajouté');
        self::assertSame(6, $family->household->precautionMonths, 'la cible la plus haute');
        self::assertSame([1, 2, 2], array_map(static fn(BudgetCategory $category): ?int => $category->adult, $family->categories), 'catégories propres à chaque membre');
        $budget = $family->budgetAt(Month::fromString('2026-11'));
        self::assertNotNull($budget);
        self::assertSame('2026-11', $budget->month->toString(), 'le mois le plus récent des membres');
        self::assertSame([1 => 300_000, 2 => 250_000], $budget->incomes);
        $overview = new BudgetOverview($family->household, $budget, $family->categories, $family->lines);
        self::assertSame(0, $overview->jointTotal, 'pas de compte commun');
        self::assertSame(550_000, $overview->income);
        self::assertSame(100_000 + 80_000 + 10_000, $overview->spending());
        self::assertSame([1 => 100_000 + 10_000, 2 => 80_000], $overview->childCosts, 'Léa : le loyer d\'Alex et le sport chez Sam ; Tom : le loyer de Sam');
        self::assertSame(300_000 - 100_000, $overview->remainder(1));
    }

    public function testFamilyWithoutBudgetForTheMonth(): void
    {
        $alex = self::book(1, 'Alex', [], ['2026-12' => 300_000]);

        self::assertNull(BudgetBook::family([['Alex', $alex]], Month::fromString('2026-11')));
        self::assertNull(BudgetBook::family([], Month::fromString('2026-11')));
        self::assertSame('2026-06', BudgetBook::firstMonthOf($alex, self::book(2, 'Sam', [], ['2026-06' => 1]))?->toString());
        self::assertNull(BudgetBook::firstMonthOf());
    }

    public function testFamilyKeepsAMemberWithoutBudgetThisMonth(): void
    {
        $alex = self::book(1, 'Alex', [], ['2026-10' => 300_000]);
        $sam = self::book(2, 'Sam', [], ['2027-01' => 200_000]);

        $family = BudgetBook::family([['Alex', $alex], ['Sam', $sam]], Month::fromString('2026-11'));

        self::assertNotNull($family);
        $budget = $family->budgetAt(Month::fromString('2026-11'));
        self::assertNotNull($budget);
        self::assertSame([1 => 300_000, 2 => 0], $budget->incomes);
        self::assertSame([1_000_001 => 100_000], $budget->amounts, 'les postes de Sam valent 0');
    }

    /**
     * Budget of one adult: a needs category with an item of €1,000 per month (tied to the first child if there is
     * one), incomes of each given month. Identifiers derived from the member's rank.
     *
     * @param list<string>       $children
     * @param array<string, int> $incomes month => incomes
     */
    private static function book(int $rank, string $adult, array $children, array $incomes): BudgetBook
    {
        $id = $rank * 1_000_000 + 1;
        $kids = [];
        foreach ($children as $index => $child) {
            $kids[$index + 1] = $child;
        }
        $months = [];
        foreach ($incomes as $month => $income) {
            $months[] = new MonthlyBudget(Month::fromString($month), [1 => $income], [$id => 100_000]);
        }

        return new BudgetBook(
            new Household([1 => $adult], $kids, 3, count($kids) + 1),
            [new BudgetCategory($id, 'Logement', BudgetGroup::Needs)],
            [new BudgetLine($id, $id, 'Loyer', $kids === [] ? null : 1)],
            $months,
        );
    }
}
