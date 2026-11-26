<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\Budget;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\JointAccount;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Budget\PrecautionCoverage;
use Patrimeter\Domain\Budget\SavingsTrack;
use Patrimeter\Domain\Budget\SplitRule;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\Valuation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Household rules: split of the joint account, household invariants, emergency savings, tracking of the payments. */
final class HouseholdRulesTest extends TestCase
{
    public function testFixedShareAndProrataBothAddUpToTheTotal(): void
    {
        $couple = new Household([1 => 'Alex', 2 => 'Sam']);

        foreach ([1, 99, 455_474, 1_000_001, MonthlyBudget::MAX_CENTS] as $total) {
            foreach ([0, 1, 333_333, 500_000, 725_000, 999_999, JointAccount::FULL] as $share) {
                $parts = JointAccount::split($total, $share, $couple);
                self::assertSame($total, $parts[1] + $parts[2], "total $total, part $share");
                self::assertGreaterThanOrEqual(0, min($parts[1], $parts[2]));
            }
        }
        self::assertSame([1 => 50, 2 => 50], JointAccount::split(100, JointAccount::HALF, $couple));
        self::assertSame([1 => 1, 2 => 0], JointAccount::split(1, JointAccount::HALF, $couple), 'le demi-centime va au premier adulte');
    }

    public function testFirstShareFollowsTheRuleOfTheMonth(): void
    {
        $couple = new Household([1 => 'Alex', 2 => 'Sam']);
        $month = Month::fromString('2026-11');

        self::assertSame(250_000, JointAccount::firstShare(new MonthlyBudget($month, [1 => 100_000, 2 => 300_000]), $couple));
        self::assertSame(JointAccount::HALF, JointAccount::firstShare(new MonthlyBudget($month), $couple), 'sans revenus : moitié chacun');
        self::assertSame(0, JointAccount::firstShare(new MonthlyBudget($month, [2 => 100_000]), $couple), 'premier adulte sans revenus');
        self::assertSame(400_000, JointAccount::firstShare(new MonthlyBudget($month, [1 => 1, 2 => 1], [], SplitRule::Fixed, 400_000), $couple));
        self::assertSame(JointAccount::FULL, JointAccount::firstShare(new MonthlyBudget($month, [], [], SplitRule::Fixed, 400_000), new Household([1 => 'Alex'])), 'seul, il verse tout');
    }

    /** @return iterable<string, array{string, ?int}> */
    public static function shares(): iterable
    {
        yield 'entier' => ['40', 400_000];
        yield 'virgule' => ['72,5', 725_000];
        yield 'point et symbole' => ['33.3333 %', 333_333];
        yield 'zéro' => ['0', 0];
        yield 'cent' => ['100', JointAccount::FULL];
        yield 'au-delà de 100' => ['100,01', null];
        yield 'négatif' => ['-5', null];
        yield 'cinq décimales' => ['1,00001', null];
        yield 'texte' => ['moitié', null];
        yield 'vide' => ['', null];
    }

    #[DataProvider('shares')]
    public function testShareInput(string $input, ?int $expected): void
    {
        self::assertSame($expected, JointAccount::parseShare($input));
    }

    public function testHouseholdInvariants(): void
    {
        $household = new Household([1 => 'Alex', 2 => 'Sam'], [2 => 'Léa'], 6, 3);
        self::assertTrue($household->isCouple());
        self::assertSame([1, 2], $household->adultIds());
        self::assertSame('Léa', $household->childName(2));
        self::assertNull($household->childName(1));
        self::assertFalse($household->hasAdult(3));

        foreach ([
            static fn(): Household => new Household([]),
            static fn(): Household => new Household([2 => 'Sam']),
            static fn(): Household => new Household([1 => 'Alex', 2 => 'Sam', 3 => 'Max']),
            static fn(): Household => new Household([1 => ' ']),
            static fn(): Household => new Household([1 => "Al\nex"]),
            static fn(): Household => new Household([1 => str_repeat('a', 41)]),
            static fn(): Household => new Household([1 => 'Alex'], [1 => 'Léa'], nextChildId: 1),
            static fn(): Household => new Household([1 => 'Alex'], precautionMonths: 37),
            static fn(): Household => new Household([1 => 'Alex'], array_fill(1, 13, 'Enfant'), nextChildId: 14),
        ] as $index => $invalid) {
            try {
                $invalid();
                self::fail("Foyer invalide accepté (n° $index)");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testMonthlyAmountsStayWithinBounds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new MonthlyBudget(Month::fromString('2026-11'), [], [1 => -1]);
    }

    public function testPrecautionIsMeasuredInMonthsOfExpenses(): void
    {
        $coverage = new PrecautionCoverage(2_356_170, 462_604, 4);

        self::assertEqualsWithDelta(5.093, $coverage->months(), 0.001);
        self::assertTrue($coverage->hasTarget());
        self::assertSame(1_850_416, $coverage->target());
        self::assertSame(505_754, $coverage->gap(), 'excédent');
        self::assertSame(-50_000, (new PrecautionCoverage(250_000, 100_000, 3))->gap(), 'manque');
        self::assertNull((new PrecautionCoverage(250_000, 0, 3))->months(), 'sans dépenses');
        self::assertFalse((new PrecautionCoverage(250_000, 100_000, 0))->hasTarget(), 'cible 0 : aucune');
    }

    public function testSavingsTrackComparesThePlannedPaymentWithTheBalanceChange(): void
    {
        $line = new BudgetLine(7, 3, 'Livret A', null, 1);
        $livret = new Account(1, 'Livret A', 4, 'Livret A', Level::Precaution, Nature::Asset, '2024-01-01');
        $valuation = new Valuation([1 => ['2026-09' => 100_000, '2026-10' => 120_000, '2026-11' => 140_500]]);

        $track = SavingsTrack::of($line, $livret, 20_000, $valuation, Month::fromString('2026-11'));
        self::assertSame(20_500, $track->change);
        self::assertSame(500, $track->difference(), 'intérêts compris');

        $carried = SavingsTrack::of($line, $livret, 20_000, $valuation, Month::fromString('2026-12'));
        self::assertNull($carried->change, 'relevé du mois pas encore saisi');
        self::assertNull($carried->difference());

        $opened = SavingsTrack::of($line, $livret, 20_000, $valuation, Month::fromString('2026-09'));
        self::assertSame(100_000, $opened->change, 'compte ouvert ce mois-ci : depuis zéro');

        $loan = new Account(2, 'Prêt', 20, 'Crédit', Level::Residence, Nature::Liability, '2024-01-01');
        $repaid = SavingsTrack::of($line, $loan, 80_000, new Valuation([2 => ['2026-10' => 9_000_000, '2026-11' => 8_920_000]]), Month::fromString('2026-11'));
        self::assertSame(80_000, $repaid->change, 'capital remboursé');
    }

    public function testAChildOfWorkingAgeCanHaveIncomes(): void
    {
        $household = new Household([1 => 'Flo'], [1 => 'Eva', 2 => 'Tom', 3 => 'Lou'], 3, 4, [1 => 1985], [1 => 2010, 2 => 2011]);

        // Eva turns 16 in 2026, Tom only in 2027; Lou's year of birth is unknown (household saved before 2.26.0).
        self::assertSame([1 => 'Eva'], $household->workingChildren(Month::fromString('2026-01')));
        self::assertSame([1 => 'Eva', 2 => 'Tom'], $household->workingChildren(Month::fromString('2027-03')));
        self::assertFalse($household->canWork(3, Month::fromString('2040-01')));
        self::assertSame(1985, $household->adultBirthYear(1));
        self::assertNull($household->childBirthYear(3));
    }

    public function testTheChildIncomesAddToTheHouseholdIncomes(): void
    {
        $household = new Household([1 => 'Flo'], [1 => 'Eva'], 3, 2, [1 => 1985], [1 => 2008]);
        $overview = new BudgetOverview($household, new MonthlyBudget(Month::fromString('2026-11'), [1 => 200_000], [], childIncomes: [1 => 45_000]), [], []);

        self::assertSame(245_000, $overview->income);
    }

    public function testAnInvalidYearOfBirthIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Household([1 => 'Flo'], [], 3, 1, [1 => 1850]);
    }
}
