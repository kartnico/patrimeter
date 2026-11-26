<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Advice;
use Patrimeter\Domain\AdviceSeverity;
use Patrimeter\Domain\Budget\PrecautionCoverage;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\NetWorthSnapshot;
use Patrimeter\Domain\PyramidAdvice;
use Patrimeter\Domain\Valuation;
use PHPUnit\Framework\TestCase;

/** Pyramid recommendations: the base first, negative levels, gap with the benchmark, favourable finding. */
final class PyramidAdviceTest extends TestCase
{
    private const string MONTH = '2026-11';

    public function testRiskyInvestmentsBeforeTheBaseAreFlagged(): void
    {
        // €2,000 of emergency savings for €2,000 of spending per month (target: 3 months) and €30,000 of shares.
        $snapshot = self::snapshot([Level::Precaution->value => 200_000, Level::Actions->value => 3_000_000]);

        $advice = PyramidAdvice::of($snapshot, new PrecautionCoverage(200_000, 200_000, 3));

        self::assertSame(['base_first', 'allocation_above'], self::codes($advice));
        self::assertSame(AdviceSeverity::Warning, $advice[0]->severity);
        self::assertSame(3_000_000, $advice[0]->values['risky']);
        self::assertSame(Level::Actions, $advice[1]->values['level']);
        self::assertEqualsWithDelta(100.0, $advice[1]->values['actual'], 0.001);
        self::assertSame(25, $advice[1]->values['recommended']);
    }

    public function testWithoutBudgetOnlyAnEmptyBaseIsFlagged(): void
    {
        $risky = self::snapshot([Level::Exotique->value => 10_000, Level::FondsEuros->value => 200_000]);

        self::assertContains('base_first', self::codes(PyramidAdvice::of($risky, new PrecautionCoverage(0, 0, 3))), 'aucune précaution');
        $withBase = self::snapshot([Level::Precaution->value => 500_000, Level::Exotique->value => 10_000, Level::FondsEuros->value => 200_000]);
        self::assertNotContains('base_first', self::codes(PyramidAdvice::of($withBase, new PrecautionCoverage(500_000, 0, 3))), 'dépenses inconnues');
    }

    public function testNegativeLevelsAndUnderweightLevels(): void
    {
        // Mortgage of €250,000 for a €200,000 property, and €100,000 in euro funds.
        $snapshot = self::snapshot([Level::Immobilier->value => [20_000_000, -25_000_000], Level::FondsEuros->value => 10_000_000, Level::Precaution->value => 900_000]);

        $advice = PyramidAdvice::of($snapshot, new PrecautionCoverage(900_000, 300_000, 3));

        self::assertSame(['negative_level', 'allocation_above'], self::codes($advice));
        self::assertSame(Level::Immobilier, $advice[0]->values['level']);
        self::assertSame(-5_000_000, $advice[0]->values['amount']);
        // Investments: €100,000 of euro funds and −€50,000 of real estate, i.e. 200 % and −100 %: gaps of 155 and 125 points.
        self::assertSame(Level::FondsEuros, $advice[1]->values['level']);
    }

    public function testBalancedPyramidAndEmptyMonth(): void
    {
        $snapshot = self::snapshot([
            Level::Precaution->value => 600_000,
            Level::FondsEuros->value => 4_500_000,
            Level::Actions->value => 2_500_000,
            Level::Immobilier->value => 2_000_000,
            Level::Exotique->value => 1_000_000,
        ]);

        $advice = PyramidAdvice::of($snapshot, new PrecautionCoverage(600_000, 200_000, 3));
        self::assertSame(['balanced'], self::codes($advice));
        self::assertSame(AdviceSeverity::Ok, $advice[0]->severity);

        self::assertSame([], PyramidAdvice::of(self::snapshot([]), new PrecautionCoverage(0, 0, 3)));
    }

    public function testSmallGapsWithTheBenchmarkAreNotFlagged(): void
    {
        // Euro funds 59 %, shares 21 %, real estate 15 %, alternative 5 %: gaps of 14, 4, 10 and 0 points.
        $snapshot = self::snapshot([
            Level::Precaution->value => 600_000,
            Level::FondsEuros->value => 5_900_000,
            Level::Actions->value => 2_100_000,
            Level::Immobilier->value => 1_500_000,
            Level::Exotique->value => 500_000,
        ]);

        self::assertSame(['balanced'], self::codes(PyramidAdvice::of($snapshot, new PrecautionCoverage(600_000, 200_000, 3))));
    }

    public function testSeverityOrdersTheAdvice(): void
    {
        self::assertSame([0, 1, 2, 3], array_map(static fn(AdviceSeverity $severity): int => $severity->rank(), AdviceSeverity::cases()));
    }

    /**
     * Month with one account per amount: one integer per level, or several (a negative amount is a loan).
     *
     * @param array<string, int|list<int>> $levels level code => cents
     */
    private static function snapshot(array $levels): NetWorthSnapshot
    {
        $accounts = [];
        $statements = [];
        $id = 0;
        foreach ($levels as $code => $amounts) {
            foreach (is_array($amounts) ? $amounts : [$amounts] as $cents) {
                ++$id;
                $accounts[] = new Account($id, "Compte $id", 1, 'Type', Level::from($code), $cents < 0 ? Nature::Liability : Nature::Asset, '2020-01-01');
                $statements[$id] = [self::MONTH => abs($cents)];
            }
        }

        return (new NetWorthCalculator($accounts, new Valuation($statements)))->snapshot(Month::fromString(self::MONTH));
    }

    /**
     * @param list<Advice> $advice
     *
     * @return list<string>
     */
    private static function codes(array $advice): array
    {
        return array_map(static fn(Advice $item): string => $item->code, $advice);
    }
}
