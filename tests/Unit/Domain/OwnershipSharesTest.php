<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\LoanMode;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Domain\Valuation;
use PHPUnit\Framework\TestCase;

/** Reference dataset of the ownership shares: shares that change, loans pro rata and in their own amount. */
final class OwnershipSharesTest extends TestCase
{
    private const int HOUSE = 1;
    private const int PRORATA_LOAN = 2;
    private const int OWN_LOAN = 3;
    private const int UNLINKED_LOAN = 4;
    private const int SAVINGS = 5;

    public function testShareInForceIsTheLastReachedEffectiveMonth(): void
    {
        $shares = new OwnershipShares([1 => ['2024-01' => 500_000, '2025-07' => 750_000]]);

        self::assertSame(OwnershipShares::FULL, $shares->at(1, Month::fromString('2023-12')), '100 % avant le premier mois d\'effet');
        self::assertSame(500_000, $shares->at(1, Month::fromString('2024-01')));
        self::assertSame(500_000, $shares->at(1, Month::fromString('2025-06')));
        self::assertSame(750_000, $shares->at(1, Month::fromString('2025-07')));
        self::assertSame(OwnershipShares::FULL, $shares->at(2, Month::fromString('2025-07')), 'aucune part : 100 %');
    }

    public function testRoundingToTheNearestCentAwayFromZeroOnTies(): void
    {
        self::assertSame(100, OwnershipShares::apply(100, OwnershipShares::FULL));
        self::assertSame(33, OwnershipShares::apply(100, 333_333));
        self::assertSame(1, OwnershipShares::apply(1, 500_000), 'demi-centime arrondi vers le haut');
        self::assertSame(-1, OwnershipShares::apply(-1, 500_000), 'symétrique pour un montant négatif');
        self::assertSame(0, OwnershipShares::apply(1, 499_999));
        self::assertSame(333_332_666_667_000, OwnershipShares::apply(999_999_000_000_000, 333_333), 'grands montants, sans dépassement');
    }

    public function testPercentInputAndDisplay(): void
    {
        self::assertSame(333_333, OwnershipShares::parsePercent('33,3333'));
        self::assertSame(500_000, OwnershipShares::parsePercent(' 50 % '));
        self::assertSame(1_000_000, OwnershipShares::parsePercent('100'));
        self::assertSame(125_000, OwnershipShares::parsePercent('12.5'));
        foreach (['0', '100,01', '-5', 'abc', '', '33,33333'] as $invalid) {
            self::assertNull(OwnershipShares::parsePercent($invalid), $invalid);
        }
        self::assertSame('33,3333', OwnershipShares::formatPercent(333_333));
        self::assertSame('50', OwnershipShares::formatPercent(500_000));
        self::assertSame('12,5', OwnershipShares::formatPercent(125_000));
        self::assertSame('100', OwnershipShares::formatPercent(1_000_000));
        self::assertSame('0,0001', OwnershipShares::formatPercent(1));
    }

    public function testNetWorthFollowsSharesAndLoanModes(): void
    {
        $calculator = new NetWorthCalculator(
            [
                new Account(self::HOUSE, 'Maison', 1, 'RP', Level::Residence, Nature::Asset, '2024-01-01'),
                new Account(self::PRORATA_LOAN, 'Prêt commun', 2, 'Crédit', Level::Residence, Nature::Liability, '2024-01-01', linkedAssetId: self::HOUSE),
                new Account(self::OWN_LOAN, 'Prêt personnel', 2, 'Crédit', Level::Residence, Nature::Liability, '2024-01-01', linkedAssetId: self::HOUSE, loanMode: LoanMode::Own),
                new Account(self::UNLINKED_LOAN, 'Prêt libre', 2, 'Crédit', Level::Residence, Nature::Liability, '2024-01-01'),
                new Account(self::SAVINGS, 'Livret', 3, 'Livret', Level::Precaution, Nature::Asset, '2024-01-01'),
            ],
            new Valuation([
                self::HOUSE => ['2024-01' => 30_000_000, '2025-07' => 40_000_000],
                self::PRORATA_LOAN => ['2024-01' => 10_000_000],
                self::OWN_LOAN => ['2024-01' => 1_000_000],
                self::UNLINKED_LOAN => ['2024-01' => 100_000],
                self::SAVINGS => ['2024-01' => 500_000],
            ]),
            new OwnershipShares([self::HOUSE => ['2024-01' => 500_000, '2025-07' => 750_000], self::SAVINGS => ['2024-01' => 500_000]]),
        );

        $first = $calculator->snapshot(Month::fromString('2024-01'));
        self::assertSame(15_000_000, $first->level(Level::Residence)->assets, 'maison à 50 %');
        self::assertSame(5_000_000 + 1_000_000 + 100_000, $first->level(Level::Residence)->liabilities, 'prorata à 50 %, montant propre et crédit libre entiers');
        self::assertSame(250_000, $first->level(Level::Precaution)->assets);

        $later = $calculator->snapshot(Month::fromString('2025-07'));
        self::assertSame(30_000_000, $later->level(Level::Residence)->assets, 'nouvelle valeur, nouvelle part : 75 %');
        self::assertSame(7_500_000 + 1_000_000 + 100_000, $later->level(Level::Residence)->liabilities);
        self::assertSame(30_000_000 - 8_600_000 + 250_000, $later->net());
    }
}
