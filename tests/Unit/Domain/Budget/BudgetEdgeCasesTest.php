<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\Budget;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\JointAccount;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Budget\PrecautionCoverage;
use Patrimeter\Domain\Budget\SavingsStatus;
use Patrimeter\Domain\Budget\SavingsTrack;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use PHPUnit\Framework\TestCase;

/** Edge cases of the budget: bounds of the household and of the amounts, missing adult, balanced or exceeded budget. */
final class BudgetEdgeCasesTest extends TestCase
{
    public function testTheRuleIsFiftyThirtyTwenty(): void
    {
        self::assertSame(['Besoins', 'Envies', 'Épargne'], array_map(static fn(BudgetGroup $group): string => $group->label(), BudgetGroup::cases()));
        self::assertSame([50, 30, 20], array_map(static fn(BudgetGroup $group): int => $group->target(), BudgetGroup::cases()));
        self::assertSame(['needs', 'wants', 'savings'], BudgetGroup::values());
    }

    public function testHouseholdBounds(): void
    {
        self::assertSame(1, (new Household([1 => 'Alex']))->nextChildId, 'premier enfant : numéro 1');
        self::assertSame(Household::DEFAULT_PRECAUTION_MONTHS, (new Household([1 => 'Alex']))->precautionMonths);
        self::assertCount(Household::MAX_CHILDREN, (new Household([1 => 'Alex'], array_fill(1, 12, 'Enfant'), nextChildId: 13))->children, 'douze enfants');
        self::assertSame(0, (new Household([1 => 'Alex'], precautionMonths: 0))->precautionMonths, 'pas de cible');
        self::assertSame(36, (new Household([1 => 'Alex'], precautionMonths: 36))->precautionMonths);
        self::assertTrue(Household::isValidName('Léa'));
        self::assertFalse(Household::isValidName("\t"));

        foreach ([
            static fn(): Household => new Household([1 => 'Alex'], [1 => ' '], nextChildId: 2),
            static fn(): Household => new Household([1 => 'Alex', 2 => '']),
            static fn(): Household => new Household([1 => 'Alex'], [0 => 'Léa'], nextChildId: 2),
            static fn(): Household => new Household([1 => 'Alex'], precautionMonths: -1),
        ] as $index => $invalid) {
            try {
                $invalid();
                self::fail("Foyer invalide accepté (n° $index)");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMonthlyAmountBounds(): void
    {
        $month = Month::fromString('2026-11');
        $budget = new MonthlyBudget($month, [1 => MonthlyBudget::MAX_CENTS], [3 => 0], firstAdultShare: JointAccount::FULL);
        self::assertSame(JointAccount::FULL, $budget->firstAdultShare, '100 % : le premier adulte verse tout');
        self::assertSame(0, $budget->income(2), 'adulte sans revenus saisis');
        self::assertSame(0, $budget->amount(99), 'poste sans montant');

        foreach ([
            static fn(): MonthlyBudget => new MonthlyBudget($month, [1 => -1]),
            static fn(): MonthlyBudget => new MonthlyBudget($month, [], [1 => MonthlyBudget::MAX_CENTS + 1]),
            static fn(): MonthlyBudget => new MonthlyBudget($month, firstAdultShare: JointAccount::FULL + 1),
            static fn(): MonthlyBudget => new MonthlyBudget($month, firstAdultShare: -1),
        ] as $index => $invalid) {
            try {
                $invalid();
                self::fail("Montants invalides acceptés (n° $index)");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testOverviewOfAHouseholdWithAnAbsentAdult(): void
    {
        $household = new Household([1 => 'Alex']);
        // The category of a missing adult comes first: the categories kept stay a list.
        $categories = [new BudgetCategory(1, 'Loisirs de Sam', BudgetGroup::Wants, 2), new BudgetCategory(2, 'Logement', BudgetGroup::Needs)];
        $lines = [new BudgetLine(1, 1, 'Sport'), new BudgetLine(2, 2, 'Loyer'), new BudgetLine(3, 2, 'Charges')];
        $budget = new MonthlyBudget(Month::fromString('2026-11'), [1 => 100_000, 2 => 5_000], [1 => 7_000, 2 => 60_000, 3 => 10_000]);

        $overview = new BudgetOverview($household, $budget, $categories, $lines);

        self::assertSame([0], array_keys($overview->categories));
        self::assertEquals([new BudgetLine(2, 2, 'Loyer'), new BudgetLine(3, 2, 'Charges')], $overview->lines(2));
        self::assertSame(70_000, $overview->categoryTotal(2));
        self::assertSame(0, $overview->categoryTotal(1), 'catégorie ignorée');
        self::assertSame(5_000, $overview->remainder(2), 'adulte hors du foyer : ni versement ni dépenses propres');
        self::assertSame(30_000, $overview->remainder(1));

        $mixed = new BudgetOverview($household, $budget, [new BudgetCategory(3, 'Sport', BudgetGroup::Wants, 1), new BudgetCategory(2, 'Logement', BudgetGroup::Needs)], $lines);
        self::assertSame([0], array_keys($mixed->categoriesOf(null)), 'catégories communes en liste');
        self::assertSame([0], array_keys($mixed->categoriesOf(1)));
    }

    public function testPossibleSavingsRateNeverCountsAnOverspend(): void
    {
        $household = new Household([1 => 'Alex']);
        $categories = [new BudgetCategory(1, 'Logement', BudgetGroup::Needs), new BudgetCategory(2, 'Épargne', BudgetGroup::Savings)];
        $lines = [new BudgetLine(1, 1, 'Loyer'), new BudgetLine(2, 2, 'Livret')];

        $balanced = new BudgetOverview($household, new MonthlyBudget(Month::fromString('2026-11'), [1 => 100_000], [1 => 80_000, 2 => 20_000]), $categories, $lines);
        self::assertSame(0, $balanced->available());
        self::assertSame($balanced->savingsRate(), $balanced->possibleSavingsRate(), 'rien de disponible');
        self::assertSame(20.0, $balanced->savingsRate());

        $overspent = new BudgetOverview($household, new MonthlyBudget(Month::fromString('2026-11'), [1 => 100_000], [1 => 90_000, 2 => 20_000]), $categories, $lines);
        self::assertSame(-10_000, $overspent->available());
        self::assertSame($overspent->savingsRate(), $overspent->possibleSavingsRate(), 'un dépassement ne retire rien');

        $tiny = new BudgetOverview($household, new MonthlyBudget(Month::fromString('2026-11'), [1 => 1], []), $categories, $lines);
        self::assertSame(100.0, $tiny->shareOfIncome(1), 'un centime de revenus suffit');
    }

    public function testSavingsStatusComparesTheBalanceChangeWithThePlannedPayment(): void
    {
        $line = new BudgetLine(1, 1, 'Livret A');
        $account = new Account(7, 'Livret A', 1, 'Livret A', Level::Precaution, Nature::Asset, '2020-01-01');

        self::assertSame(SavingsStatus::Unknown, (new SavingsTrack($line, $account, 10_000, null))->status());
        self::assertSame(SavingsStatus::Reached, (new SavingsTrack($line, $account, 10_000, 10_000))->status());
        self::assertSame(SavingsStatus::Below, (new SavingsTrack($line, $account, 10_000, 9_999))->status());
        self::assertSame(SavingsStatus::Below, (new SavingsTrack($line, $account, 10_000, 0))->status());
        self::assertSame(SavingsStatus::Decrease, (new SavingsTrack($line, $account, 10_000, -1))->status());
    }

    public function testPrecautionTargetNeedsMonthsAndExpenses(): void
    {
        self::assertFalse((new PrecautionCoverage(250_000, 0, 3))->hasTarget(), 'sans dépenses');
        self::assertFalse((new PrecautionCoverage(250_000, 100_000, 0))->hasTarget(), 'sans cible');
        self::assertTrue((new PrecautionCoverage(0, 1, 1))->hasTarget());
    }
}
