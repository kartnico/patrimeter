<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\Budget;

use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\JointAccount;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Budget\SplitRule;
use Patrimeter\Domain\Month;
use PHPUnit\Framework\TestCase;

/**
 * Budget of a month, calculated. Reference dataset: a couple's spreadsheet (shared budget of €4,554.74, fixed share of
 * 27.5 % for the first adult), whose results are taken as they are.
 */
final class BudgetOverviewTest extends TestCase
{
    private const int HOUSING = 1;
    private const int DAILY = 2;
    private const int LEISURE = 3;
    private const int FAMILY_SAVINGS = 4;
    private const int FLO_EXPENSES = 5;
    private const int FLO_LEISURE = 6;
    private const int FLO_SAVINGS = 7;
    private const int NICO_EXPENSES = 8;
    private const int NICO_LEISURE = 9;
    private const int NICO_SAVINGS = 10;

    public function testReproducesTheCouplesSpreadsheet(): void
    {
        $overview = self::spreadsheet(SplitRule::Fixed, 275_000);

        self::assertSame(600_300, $overview->income, 'salaires : 1 860,00 + 4 143,00');
        self::assertSame(455_474, $overview->jointTotal, 'total des dépenses de la famille');
        self::assertSame([1 => 125_255, 2 => 330_219], $overview->jointParts, 'parts de 27,5 % et 72,5 %');
        self::assertSame(1_515, $overview->remainder(1), 'restant après dépenses personnelles : 15,15 €');
        self::assertSame(481, $overview->remainder(2), 'restant après dépenses personnelles : 4,81 €');
        self::assertSame(['needs' => 452_604, 'wants' => 113_700, 'savings' => 32_000], $overview->groupTotals);
        self::assertSame(1_996, $overview->available(), 'ce qui reste au foyer : la somme des deux restants');
        self::assertSame(566_304, $overview->expenses(), 'besoins et envies, sans l\'épargne');
        self::assertSame(32_000, $overview->savings());
    }

    public function testJointAccountIsSharedInProportionToIncome(): void
    {
        $overview = self::spreadsheet(SplitRule::Income, 0);

        self::assertSame(309_845, $overview->firstAdultShare, '1 860 / 6 003, en millionièmes');
        self::assertSame([1 => 141_126, 2 => 314_348], $overview->jointParts);
        self::assertSame($overview->jointTotal, array_sum($overview->jointParts), 'les versements font le total, au centime');
    }

    public function testShareOfIncomeAndSavingsRates(): void
    {
        $overview = self::spreadsheet(SplitRule::Fixed, 275_000);

        self::assertEqualsWithDelta(75.396, $overview->shareOfIncome($overview->groupTotal(BudgetGroup::Needs)), 0.001);
        self::assertEqualsWithDelta(5.331, $overview->savingsRate(), 0.001);
        self::assertEqualsWithDelta(5.663, $overview->possibleSavingsRate(), 0.001, 'épargne et reste disponible');
    }

    public function testWithoutIncomeNoShareIsComputed(): void
    {
        $household = new Household([1 => 'Alex']);
        $overview = new BudgetOverview($household, new MonthlyBudget(Month::fromString('2026-11'), [], [1 => 1_000]), [new BudgetCategory(1, 'Logement', BudgetGroup::Needs)], [new BudgetLine(1, 1, 'Loyer')]);

        self::assertNull($overview->shareOfIncome(1_000));
        self::assertNull($overview->savingsRate());
        self::assertSame(-1_000, $overview->available(), 'dépassement');
        self::assertSame([1 => 1_000], $overview->jointParts, 'un adulte seul verse tout');
        self::assertSame(JointAccount::FULL, $overview->firstAdultShare);
    }

    public function testCostOfEachChildIncludesSharedAndPersonalItems(): void
    {
        $household = new Household([1 => 'Nico', 2 => 'Flo'], [1 => 'Eva', 3 => 'Tom'], nextChildId: 4);
        $categories = [new BudgetCategory(1, 'Vie courante', BudgetGroup::Needs), new BudgetCategory(2, 'Épargne', BudgetGroup::Savings, 1)];
        $lines = [
            new BudgetLine(1, 1, 'Cantine', 1),
            new BudgetLine(2, 1, 'Courses'),
            new BudgetLine(3, 2, 'Épargne Eva', 1),
            new BudgetLine(4, 1, 'Ancien enfant', 2),
        ];
        $budget = new MonthlyBudget(Month::fromString('2026-11'), [1 => 300_000], [1 => 4_800, 2 => 120_000, 3 => 5_000, 4 => 9_900]);

        $overview = new BudgetOverview($household, $budget, $categories, $lines);

        self::assertSame([1 => 9_800, 3 => 0], $overview->childCosts, 'un enfant retiré du foyer n\'est plus compté');
    }

    public function testChildCostsAreShownOnlyWithALineAttachedToAChildOfTheHousehold(): void
    {
        $month = Month::fromString('2026-11');
        $categories = [new BudgetCategory(1, 'Vie courante', BudgetGroup::Needs)];
        $budget = new MonthlyBudget($month, [1 => 300_000], [1 => 4_800, 2 => 9_900]);

        $without = new BudgetOverview(new Household([1 => 'Nico']), $budget, $categories, [new BudgetLine(1, 1, 'Courses')]);
        self::assertFalse($without->hasChildLines, 'no child');

        $household = new Household([1 => 'Nico'], [1 => 'Eva'], nextChildId: 3);
        $unattached = new BudgetOverview($household, $budget, $categories, [new BudgetLine(1, 1, 'Courses'), new BudgetLine(2, 1, 'Ancien enfant', 2)]);
        self::assertFalse($unattached->hasChildLines, 'a child, but no line attached to a child of the household');
        self::assertSame([1 => 0], $unattached->childCosts);

        $attached = new BudgetOverview($household, $budget, $categories, [new BudgetLine(1, 1, 'Cantine', 1)]);
        self::assertTrue($attached->hasChildLines);
        self::assertSame([1 => 4_800], $attached->childCosts);
    }

    public function testCategoriesOfAnAdultWhoLeftTheHouseholdAreIgnored(): void
    {
        $household = new Household([1 => 'Alex']);
        $categories = [new BudgetCategory(1, 'Commun', BudgetGroup::Needs), new BudgetCategory(2, 'Loisirs de Sam', BudgetGroup::Wants, 2)];
        $lines = [new BudgetLine(1, 1, 'Loyer'), new BudgetLine(2, 2, 'Sport'), new BudgetLine(3, 99, 'Orphelin')];
        $budget = new MonthlyBudget(Month::fromString('2026-11'), [1 => 200_000, 2 => 100_000], [1 => 50_000, 2 => 7_000, 3 => 1_000]);

        $overview = new BudgetOverview($household, $budget, $categories, $lines);

        self::assertSame([1], array_map(static fn(BudgetCategory $category): int => $category->id, $overview->categories));
        self::assertSame(200_000, $overview->income, 'revenus du seul adulte du foyer');
        self::assertSame(50_000, $overview->spending());
        self::assertSame([], $overview->lines(2));
        self::assertSame([1], array_map(static fn(BudgetCategory $category): int => $category->id, $overview->categoriesOf(null)));
        self::assertSame([], $overview->categoriesOf(1));
    }

    /** A couple's sheet: shared budget, then each one's spending and savings (amounts in cents). */
    private static function spreadsheet(SplitRule $rule, int $firstShare): BudgetOverview
    {
        $household = new Household([1 => 'Flo', 2 => 'Nico'], [1 => 'Eva'], 4, 2);
        $categories = [
            new BudgetCategory(self::HOUSING, 'Logement', BudgetGroup::Needs),
            new BudgetCategory(self::DAILY, 'Dépenses courantes', BudgetGroup::Needs),
            new BudgetCategory(self::LEISURE, 'Loisirs', BudgetGroup::Wants),
            new BudgetCategory(self::FAMILY_SAVINGS, 'Épargne', BudgetGroup::Savings),
            new BudgetCategory(self::FLO_EXPENSES, 'Dépenses', BudgetGroup::Needs, 1),
            new BudgetCategory(self::FLO_LEISURE, 'Loisirs', BudgetGroup::Wants, 1),
            new BudgetCategory(self::FLO_SAVINGS, 'Épargne', BudgetGroup::Savings, 1),
            new BudgetCategory(self::NICO_EXPENSES, 'Dépenses', BudgetGroup::Needs, 2),
            new BudgetCategory(self::NICO_LEISURE, 'Loisirs', BudgetGroup::Wants, 2),
            new BudgetCategory(self::NICO_SAVINGS, 'Épargne', BudgetGroup::Savings, 2),
        ];
        $amounts = [
            // Housing: loan, loan insurance, charges, taxes, insurances, internet, electricity, spending.
            [self::HOUSING, 150_801], [self::HOUSING, 5_273], [self::HOUSING, 15_000], [self::HOUSING, 8_800],
            [self::HOUSING, 16_000], [self::HOUSING, 6_000], [self::HOUSING, 21_500], [self::HOUSING, 10_000],
            // Everyday spending: groceries, purchases and Eva's school canteen.
            [self::DAILY, 120_000], [self::DAILY, 5_000], [self::DAILY, 4_800],
            // Loisirs : cadeaux, abonnements, sorties, sports d'Eva.
            [self::LEISURE, 30_000], [self::LEISURE, 600], [self::LEISURE, 700], [self::LEISURE, 2_000],
            [self::LEISURE, 3_000], [self::LEISURE, 20_000], [self::LEISURE, 36_000],
            [self::FAMILY_SAVINGS, 0],
            [self::FLO_EXPENSES, 20_830], [self::FLO_LEISURE, 16_400], [self::FLO_SAVINGS, 22_000],
            [self::NICO_EXPENSES, 68_600], [self::NICO_LEISURE, 5_000], [self::NICO_SAVINGS, 10_000],
        ];
        $lines = [];
        $cents = [];
        foreach ($amounts as $index => [$category, $amount]) {
            $lines[] = new BudgetLine($index + 1, $category, 'Poste ' . ($index + 1));
            $cents[$index + 1] = $amount;
        }
        $budget = new MonthlyBudget(Month::fromString('2026-11'), [1 => 186_000, 2 => 414_300], $cents, $rule, $firstShare);

        return new BudgetOverview($household, $budget, $categories, $lines);
    }
}
