<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\Valuation;
use PHPUnit\Framework\TestCase;

/** Net worth by level. */
final class NetWorthCalculatorTest extends TestCase
{
    private const int HOUSE = 1;
    private const int MORTGAGE = 2;
    private const int CURRENT = 3;
    private const int LIVRET = 4;
    private const int AV_EUROS = 5;
    private const int PEA = 6;
    private const int SCPI = 7;
    private const int RENTAL_LOAN = 8;
    private const int CRYPTO = 9;
    private const int CLOSED = 10;

    private function calculator(): NetWorthCalculator
    {
        $a = static fn(int $id, Level $level, Nature $nature = Nature::Asset, string $opened = '2020-01-01', ?string $closed = null): Account
            => new Account($id, "Compte $id", 1, 'Type', $level, $nature, $opened, $closed);

        $accounts = [
            $a(self::HOUSE, Level::Residence),
            $a(self::MORTGAGE, Level::Residence, Nature::Liability),
            $a(self::CURRENT, Level::Precaution),
            $a(self::LIVRET, Level::Precaution),
            $a(self::AV_EUROS, Level::FondsEuros),
            $a(self::PEA, Level::Actions),
            $a(self::SCPI, Level::Immobilier),
            $a(self::RENTAL_LOAN, Level::Immobilier, Nature::Liability),
            $a(self::CRYPTO, Level::Exotique, Nature::Asset, '2026-02-01'),
            $a(self::CLOSED, Level::Actions, Nature::Asset, '2020-01-01', '2026-02-20'),
        ];

        $valuation = new Valuation([
            self::HOUSE => ['2026-01' => 30_000_000],
            self::MORTGAGE => ['2026-01' => 20_000_000, '2026-02' => 19_900_000],
            self::CURRENT => ['2026-01' => 250_000, '2026-02' => -15_000],
            self::LIVRET => ['2026-01' => 2_295_000],
            self::AV_EUROS => ['2026-01' => 5_000_000],
            self::PEA => ['2026-01' => 4_000_000, '2026-02' => 4_200_000],
            self::SCPI => ['2026-01' => 1_000_000],
            self::RENTAL_LOAN => ['2026-01' => 1_500_000],
            self::CRYPTO => ['2026-02' => 300_000],
            self::CLOSED => ['2026-01' => 700_000],
        ]);

        return new NetWorthCalculator($accounts, $valuation);
    }

    public function testNetByLevelWithLiabilities(): void
    {
        $s = $this->calculator()->snapshot(Month::fromString('2026-01'));

        self::assertSame(10_000_000, $s->level(Level::Residence)->net(), 'bien − crédit RP');
        self::assertSame(30_000_000, $s->level(Level::Residence)->assets);
        self::assertSame(20_000_000, $s->level(Level::Residence)->liabilities);
        self::assertSame(2_545_000, $s->level(Level::Precaution)->net());
        self::assertSame(5_000_000, $s->level(Level::FondsEuros)->net());
        self::assertSame(4_700_000, $s->level(Level::Actions)->net(), 'PEA + compte encore ouvert en janvier');
        self::assertSame(-500_000, $s->level(Level::Immobilier)->net(), 'niveau négatif si le crédit dépasse l\'actif');
        self::assertSame(0, $s->level(Level::Exotique)->net());
        self::assertTrue($s->level(Level::Exotique)->isEmpty(), 'crypto ouvert en février seulement');

        self::assertSame(21_745_000, $s->net());
        self::assertSame(43_245_000, $s->assets());
        self::assertSame(21_500_000, $s->liabilities());
        self::assertSame($s->assets() - $s->liabilities(), $s->net());
        self::assertSame(9, $s->enteredCount);
        self::assertSame(0, $s->carriedCount);
    }

    public function testCarryAndClosingInFollowingMonth(): void
    {
        $s = $this->calculator()->snapshot(Month::fromString('2026-02'));

        // Property carried over (30,000,000) − loan entered in February (19,900,000).
        self::assertSame(10_100_000, $s->level(Level::Residence)->net());
        // Overdrawn current account + savings account carried over.
        self::assertSame(2_280_000, $s->level(Level::Precaution)->net());
        // Account closed in February: excluded; PEA entered.
        self::assertSame(4_200_000, $s->level(Level::Actions)->net());
        self::assertSame(300_000, $s->level(Level::Exotique)->net());

        self::assertSame(4, $s->enteredCount, 'crédit, compte courant, PEA, crypto');
        self::assertSame(5, $s->carriedCount);
        self::assertSame(10_100_000 + 2_280_000 + 5_000_000 + 4_200_000 - 500_000 + 300_000, $s->net());
    }

    public function testShares(): void
    {
        $s = $this->calculator()->snapshot(Month::fromString('2026-01'));
        $sum = 0.0;
        foreach (Level::ordered() as $level) {
            $sum += (float) $s->share($level);
        }
        self::assertEqualsWithDelta(100.0, $sum, 1e-9);
        self::assertEqualsWithDelta(10_000_000 * 100 / 21_745_000, $s->share(Level::Residence), 1e-9);
    }

    public function testNoShareWhenNetIsNotPositive(): void
    {
        $accounts = [new Account(1, 'Prêt', 1, 'T', Level::Residence, Nature::Liability, '2020-01-01')];
        $calc = new NetWorthCalculator($accounts, new Valuation([1 => ['2026-01' => 1000]]));
        $s = $calc->snapshot(Month::fromString('2026-01'));
        self::assertSame(-1000, $s->net());
        self::assertNull($s->share(Level::Residence));
    }

    public function testEmptySnapshot(): void
    {
        $s = (new NetWorthCalculator([], new Valuation([])))->snapshot(Month::fromString('2026-01'));
        self::assertFalse($s->hasData());
        self::assertSame(0, $s->net());
        self::assertCount(6, $s->levels);
    }

    public function testSeries(): void
    {
        $series = $this->calculator()->series(Month::fromString('2025-12'), Month::fromString('2026-03'));
        self::assertCount(4, $series);
        self::assertSame(0, $series[0]->net(), 'aucun relevé avant janvier');
        self::assertSame(21_745_000, $series[1]->net());
        self::assertSame($series[2]->net(), $series[3]->net(), 'mars entièrement reporté depuis février');
        self::assertSame(9, $series[3]->carriedCount);
        self::assertSame('2026-01', (string) $this->calculator()->firstMonth());
    }
}
