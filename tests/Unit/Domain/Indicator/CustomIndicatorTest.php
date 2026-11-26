<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\Indicator;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Indicator\CustomIndicator;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\NetWorthSnapshot;
use Patrimeter\Domain\Valuation;
use PHPUnit\Framework\TestCase;

/** Total of chosen levels and accounts, each account counted once, and its share of the net worth. */
final class CustomIndicatorTest extends TestCase
{
    private const int LIVRET = 1;
    private const int PEA = 2;
    private const int LIFE_INSURANCE = 3;
    private const int LOAN = 4;

    /** Livret A 10 000 €, PEA 30 000 €, life insurance (euro funds) 20 000 €, loan 15 000 € owed on real estate. */
    private static function snapshot(): NetWorthSnapshot
    {
        return (new NetWorthCalculator([
            new Account(self::LIVRET, 'Livret A', 1, 'Livret A', Level::Precaution, Nature::Asset, '2020-01-01', null),
            new Account(self::PEA, 'PEA', 1, 'PEA', Level::Actions, Nature::Asset, '2020-01-01', null),
            new Account(self::LIFE_INSURANCE, 'Assurance-vie', 1, 'Assurance-vie', Level::FondsEuros, Nature::Asset, '2020-01-01', null),
            new Account(self::LOAN, 'Crédit', 1, 'Crédit', Level::Immobilier, Nature::Liability, '2020-01-01', null),
        ], new Valuation([
            self::LIVRET => ['2026-11' => 1_000_000],
            self::PEA => ['2026-11' => 3_000_000],
            self::LIFE_INSURANCE => ['2026-11' => 2_000_000],
            self::LOAN => ['2026-11' => 1_500_000],
        ])))->snapshot(Month::fromString('2026-11'));
    }

    public function testLevelsAndAccountsAddUpEachAccountOnce(): void
    {
        // Retirement: the shares level, plus the life insurance; the PEA, also chosen, is already in the shares level.
        $retirement = new CustomIndicator(1, 'Retraite', [Level::Actions], [self::LIFE_INSURANCE, self::PEA], true);

        self::assertSame(5_000_000, $retirement->valueIn(self::snapshot()));
    }

    public function testALiabilityLowersTheTotal(): void
    {
        $indicator = new CustomIndicator(1, 'Épargne moins dettes', [], [self::LIVRET, self::LOAN], false);

        self::assertSame(-500_000, $indicator->valueIn(self::snapshot()));
    }

    public function testShareOfTheNetWorth(): void
    {
        // Net worth: 10 000 + 30 000 + 20 000 − 15 000 = 45 000 €; shares level 30 000 € = 66.7 %.
        $shares = new CustomIndicator(1, 'Actions', [Level::Actions], [], true);

        self::assertEqualsWithDelta(66.67, $shares->shareIn(self::snapshot()), 0.01);
    }
}
