<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\Valuation;
use Patrimeter\Domain\YearToDate;
use PHPUnit\Framework\TestCase;

/** Put into or taken out of each level since the start of the year. */
final class YearToDateTest extends TestCase
{
    private const int LIVRET = 1;
    private const int PEA = 2;
    private const int LOAN = 3;

    /** @param array<int, array<string, int>> $statements */
    private static function calculator(array $statements): NetWorthCalculator
    {
        return new NetWorthCalculator([
            new Account(self::LIVRET, 'Livret A', 1, 'Livret A', Level::Precaution, Nature::Asset, '2020-01-01', null),
            new Account(self::PEA, 'PEA', 1, 'PEA', Level::Actions, Nature::Asset, '2020-01-01', null),
            new Account(self::LOAN, 'Crédit', 1, 'Crédit', Level::Immobilier, Nature::Liability, '2020-01-01', null),
        ], new Valuation($statements));
    }

    private static function of(NetWorthCalculator $calculator, string $month): ?YearToDate
    {
        return YearToDate::of($calculator->snapshot(Month::fromString($month)), $calculator->snapshot(...));
    }

    /** @return array<string, int> change by level code */
    private static function changes(YearToDate $ytd): array
    {
        $changes = [];
        foreach ($ytd->levels as $row) {
            $changes[$row['level']->value] = $row['change'];
        }

        return $changes;
    }

    public function testChangeSinceTheEndOfThePreviousYear(): void
    {
        $calculator = self::calculator([
            self::LIVRET => ['2025-12' => 1_000_000, '2026-11' => 1_250_000],
            self::PEA => ['2025-06' => 2_000_000, '2026-03' => 1_800_000],
            self::LOAN => ['2025-12' => 500_000, '2026-11' => 400_000],
        ]);

        $ytd = self::of($calculator, '2026-11');

        self::assertNotNull($ytd);
        self::assertTrue($ytd->endOfPreviousYear);
        self::assertSame('2025-12', $ytd->reference->toString());
        self::assertSame(
            ['exotique' => 0, 'immobilier' => 100_000, 'actions' => -200_000, 'fonds_euros' => 0, 'precaution' => 250_000, 'residence' => 0],
            self::changes($ytd),
            'pyramid order, from the top; the PEA carried from June 2025, the loan repaid',
        );
        self::assertSame(150_000, $ytd->total);
    }

    public function testJanuaryComparesWithDecember(): void
    {
        $ytd = self::of(self::calculator([self::LIVRET => ['2025-12' => 1_000, '2026-01' => 1_500]]), '2026-01');

        self::assertNotNull($ytd);
        self::assertSame('2025-12', $ytd->reference->toString());
        self::assertSame(500, $ytd->total);
    }

    public function testDataStartingThisYearComparesWithJanuary(): void
    {
        $calculator = self::calculator([self::LIVRET => ['2026-01' => 1_000, '2026-11' => 4_000]]);

        $ytd = self::of($calculator, '2026-11');
        self::assertNotNull($ytd);
        self::assertFalse($ytd->endOfPreviousYear);
        self::assertSame('2026-01', $ytd->reference->toString());
        self::assertSame(3_000, $ytd->total);

        self::assertNull(self::of($calculator, '2026-01'), 'January compared with itself: nothing to show');
    }

    public function testNothingWithoutDataForTheMonthOrTheStartOfTheYear(): void
    {
        self::assertNull(self::of(self::calculator([]), '2026-11'));
        self::assertNull(self::of(self::calculator([self::LIVRET => ['2026-05' => 1_000]]), '2026-11'), 'no data in December nor in January');

        // Data at the end of the previous year, none for the month shown (account closed since).
        $closed = new NetWorthCalculator([
            new Account(self::LIVRET, 'Livret A', 1, 'Livret A', Level::Precaution, Nature::Asset, '2020-01-01', '2026-03-15'),
        ], new Valuation([self::LIVRET => ['2025-12' => 1_000]]));
        self::assertNull(self::of($closed, '2026-11'));
    }
}
