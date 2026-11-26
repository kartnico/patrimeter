<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\Goal;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Goal\GoalProgress;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\GoalStatus;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\Valuation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Where a savings goal stands: effort still needed each month, pace of the last six months, status, projected month.
 * Goal of the examples: 12 000 € on the Livret A by 2027-11, started at 6 000 € in 2026-05.
 */
final class GoalProgressTest extends TestCase
{
    private const int LIVRET = 1;
    private const int PEA = 2;
    private const int LOAN = 3;

    /** Livret A growing by $step each month from 6 000 € in 2026-05. */
    private static function calculator(int $step, string $last = '2026-11'): NetWorthCalculator
    {
        $statements = [];
        $value = 600_000;
        foreach (Month::range(Month::fromString('2026-05'), Month::fromString($last)) as $month) {
            $statements[$month->toString()] = $value;
            $value += $step;
        }

        return new NetWorthCalculator([
            new Account(self::LIVRET, 'Livret A', 1, 'Livret A', Level::Precaution, Nature::Asset, '2020-01-01', null),
            new Account(self::PEA, 'PEA', 1, 'PEA', Level::Actions, Nature::Asset, '2020-01-01', null),
            new Account(self::LOAN, 'Crédit', 1, 'Crédit', Level::Immobilier, Nature::Liability, '2020-01-01', null),
        ], new Valuation([self::LIVRET => $statements, self::PEA => ['2026-05' => 1_000_000], self::LOAN => ['2026-05' => 400_000]]));
    }

    private static function goal(GoalScope $scope = GoalScope::Accounts, ?Level $level = null, int $target = 1_200_000, string $targetMonth = '2027-11', string $start = '2026-05'): SavingsGoal
    {
        return new SavingsGoal(7, 'Apport', $target, Month::fromString($targetMonth), $scope, $level, [self::LIVRET], Month::fromString($start), 600_000);
    }

    private static function progress(int $step, ?SavingsGoal $goal = null, string $month = '2026-11'): GoalProgress
    {
        return GoalProgress::of($goal ?? self::goal(), Month::fromString($month), self::calculator($step, $month)->snapshot(...));
    }

    /** @return iterable<string, array{int, GoalStatus}> monthly step of the Livret A => status in 2026-11 */
    public static function paces(): iterable
    {
        // 2026-11: 6 000 + 6 × step; 12 months left.
        yield 'well ahead' => [60_000, GoalStatus::Ahead];       // 9 600 €: 2 400 € missing, 200 €/month needed, pace 600
        yield 'on track' => [35_000, GoalStatus::OnTrack];       // 8 100 €: 325 €/month needed, pace 350
        yield 'to watch' => [30_000, GoalStatus::Watch];         // 7 800 €: 350 €/month needed, pace 300
        yield 'late' => [10_000, GoalStatus::Late];              // 6 600 €: 450 €/month needed, pace 100
        yield 'going down' => [-5_000, GoalStatus::Late];
    }

    #[DataProvider('paces')]
    public function testTheStatusComparesThePaceWithTheEffortStillNeeded(int $step, GoalStatus $expected): void
    {
        self::assertSame($expected, self::progress($step)->status);
    }

    public function testEffortPaceProjectionAndShortfall(): void
    {
        $progress = self::progress(10_000);

        self::assertSame(660_000, $progress->value);
        self::assertSame(45_000, $progress->required, '5 400 € in 12 months');
        self::assertSame(10_000, $progress->pace);
        self::assertSame(6, $progress->paceMonths);
        self::assertSame(35_000, $progress->shortfall());
        self::assertSame('2031-05', $progress->projectedMonth?->toString(), '54 months at 100 €');
        self::assertSame(42, $progress->delayMonths());
        self::assertSame(55, $progress->percent());
        self::assertFalse($progress->overdue);
        self::assertTrue($progress->status->isAlert());
    }

    public function testTheExpectedShareFollowsThePlannedTrajectory(): void
    {
        // 6 000 € in 2026-05 to 12 000 € in 2027-11: 18 months, 333,33 € a month planned.
        self::assertSame(50, self::progress(0, month: '2026-05')->expectedPercent(), 'starting point');
        self::assertSame(66, self::progress(0)->expectedPercent(), '6 months later: 8 000 €');
        self::assertSame(100, self::progress(0, month: '2028-02')->expectedPercent(), 'after the target month');
        self::assertSame(100, self::progress(0, self::goal(target: 0))->expectedPercent(), 'nothing to reach');
        self::assertSame(100, self::progress(0, self::goal(targetMonth: '2026-05'))->expectedPercent(), 'no time left');
    }

    public function testAGoalReachedStaysReached(): void
    {
        $progress = self::progress(100_000);

        self::assertSame(GoalStatus::Reached, $progress->status);
        self::assertNull($progress->required);
        self::assertNull($progress->projectedMonth);
        self::assertSame(100, $progress->percent());
        self::assertSame(0, $progress->shortfall());
        self::assertFalse($progress->status->isAlert());
    }

    public function testTooEarlyToJudgeInTheStartMonth(): void
    {
        $progress = self::progress(10_000, self::goal(start: '2026-11'));

        self::assertSame(GoalStatus::TooEarly, $progress->status);
        self::assertNull($progress->pace);
        self::assertSame(0, $progress->paceMonths);
        self::assertNull($progress->projectedMonth);
        self::assertNull($progress->delayMonths());
    }

    public function testThePaceIsMeasuredSinceTheStartWhenItIsRecent(): void
    {
        $progress = self::progress(10_000, self::goal(start: '2026-09'));

        self::assertSame(2, $progress->paceMonths);
        self::assertSame(10_000, $progress->pace);
    }

    public function testATargetMonthPassedWithoutTheTargetIsLate(): void
    {
        $progress = self::progress(10_000, self::goal(targetMonth: '2026-10'));

        self::assertSame(GoalStatus::Late, $progress->status);
        self::assertTrue($progress->overdue);
        self::assertSame(540_000, $progress->required, 'what is still missing');
        self::assertSame(0, $progress->shortfall());
    }

    public function testThePerimeterIsTheNetWorthALevelOrTheAccounts(): void
    {
        $snapshot = self::calculator(10_000)->snapshot(Month::fromString('2026-11'));

        self::assertSame(660_000 + 1_000_000 - 400_000, self::goal(GoalScope::NetWorth)->valueIn($snapshot));
        self::assertSame(1_000_000, self::goal(GoalScope::Level, Level::Actions)->valueIn($snapshot));
        self::assertSame(-400_000, self::goal(GoalScope::Level, Level::Immobilier)->valueIn($snapshot));
        self::assertSame(0, self::goal(GoalScope::Level)->valueIn($snapshot), 'level missing');
        self::assertSame(660_000, self::goal()->valueIn($snapshot));
        $loan = new SavingsGoal(1, 'Crédit', 1, Month::fromString('2027-01'), GoalScope::Accounts, null, [self::LOAN, self::LIVRET], Month::fromString('2026-05'), 0);
        self::assertSame(260_000, $loan->valueIn($snapshot), 'a loan counts negatively');
    }

    public function testHistoryOfTheLastMonthsFromTheStart(): void
    {
        $history = GoalProgress::history(self::goal(start: '2026-09'), Month::fromString('2026-11'), self::calculator(10_000)->snapshot(...));

        self::assertSame(['2026-09', '2026-10', '2026-11'], array_map(static fn(GoalProgress $p): string => $p->month->toString(), $history));
        self::assertSame(GoalStatus::TooEarly, $history[0]->status);
        self::assertCount(6, GoalProgress::history(self::goal(start: '2025-01'), Month::fromString('2026-11'), self::calculator(10_000)->snapshot(...)));
        self::assertSame([], GoalProgress::history(self::goal(start: '2027-01'), Month::fromString('2026-11'), self::calculator(10_000)->snapshot(...)));
    }

    public function testNameAndLabels(): void
    {
        self::assertTrue(SavingsGoal::isValidName('Apport maison'));
        self::assertFalse(SavingsGoal::isValidName(''));
        self::assertFalse(SavingsGoal::isValidName(str_repeat('a', 61)));
        self::assertFalse(SavingsGoal::isValidName("a\nb"));
        self::assertSame('En retard', GoalStatus::Late->label());
        self::assertSame('Trop tôt pour juger', GoalStatus::TooEarly->label());
        self::assertSame('Des comptes choisis', GoalScope::Accounts->label());
        self::assertSame(3, self::goal()->withId(3)->id);
        self::assertSame(100, GoalProgress::of(new SavingsGoal(1, 'Zéro', 0, Month::fromString('2027-01'), GoalScope::NetWorth, null, [], Month::fromString('2026-05'), 0), Month::fromString('2026-11'), self::calculator(0)->snapshot(...))->percent());
    }

    public function testATargetThatFollowsTheBudgetAndThePlannedSavings(): void
    {
        $goal = new SavingsGoal(7, 'Précaution', 1_200_000, Month::fromString('2027-11'), GoalScope::Accounts, null, [self::LIVRET], Month::fromString('2026-05'), 600_000, 6);
        $progress = GoalProgress::of($goal, Month::fromString('2026-11'), self::calculator(10_000)->snapshot(...), static fn(Month $m): int => 900_000, 20_000);

        self::assertSame(900_000, $progress->goal->targetCents, 'six months of 1 500 € of spending');
        self::assertSame(6, $progress->goal->spendingMonths);
        self::assertSame(20_000, $progress->required);
        self::assertSame(20_000, $progress->planned);
        self::assertSame(0, $progress->plannedShortfall());
        self::assertSame(1_200_000, GoalProgress::of($goal, Month::fromString('2026-11'), self::calculator(10_000)->snapshot(...), static fn(Month $m): ?int => null)->goal->targetCents, 'no budget: the amount saved');
        self::assertNull(self::progress(10_000)->plannedShortfall());
        $history = GoalProgress::history($goal, Month::fromString('2026-11'), self::calculator(10_000)->snapshot(...), 2, static fn(Month $m): int => 650_000);
        self::assertSame(['2026-10', '2026-11'], array_map(static fn(GoalProgress $p): string => $p->month->toString(), $history));
        self::assertSame(GoalStatus::Reached, $history[1]->status, '6 600 € for a target of 6 500 € that month');
    }

    public function testMonthsUntil(): void
    {
        self::assertSame(12, Month::fromString('2026-11')->monthsUntil(Month::fromString('2027-11')));
        self::assertSame(-2, Month::fromString('2026-11')->monthsUntil(Month::fromString('2026-09')));
    }
}
