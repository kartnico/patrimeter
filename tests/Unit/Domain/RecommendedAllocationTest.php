<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\NetWorthSnapshot;
use Patrimeter\Domain\RecommendedAllocation;
use Patrimeter\Domain\Valuation;
use PHPUnit\Framework\TestCase;

final class RecommendedAllocationTest extends TestCase
{
    public function testBenchmarkSkipsResidenceAndEmergencySavings(): void
    {
        self::assertArrayNotHasKey(Level::Residence->value, RecommendedAllocation::SHARES);
        self::assertArrayNotHasKey(Level::Precaution->value, RecommendedAllocation::SHARES);
        self::assertStringStartsWith('https://avenuedesinvestisseurs.fr/', RecommendedAllocation::SOURCE_URL);
    }

    public function testSharesAreComputedOnInvestmentsOnly(): void
    {
        // Residence and emergency savings do not count: €40,000 of investments in total.
        $rows = RecommendedAllocation::compare(self::snapshot([
            Level::Residence->value => 30_000_000,
            Level::Precaution->value => 1_000_000,
            Level::FondsEuros->value => 2_000_000,
            Level::Actions->value => 1_000_000,
            Level::Immobilier->value => 800_000,
            Level::Exotique->value => 200_000,
        ]));

        self::assertNotNull($rows);
        self::assertSame([Level::FondsEuros, Level::Actions, Level::Immobilier, Level::Exotique], array_column($rows, 'level'));
        self::assertEqualsWithDelta([50.0, 25.0, 20.0, 5.0], array_column($rows, 'actual'), 0.001);
        self::assertSame([45, 25, 25, 5], array_column($rows, 'recommended'));
    }

    public function testNoComparisonWithoutPositiveInvestments(): void
    {
        self::assertNull(RecommendedAllocation::compare(self::snapshot([Level::Residence->value => 30_000_000, Level::Precaution->value => 500_000])));
    }

    /** @param array<string, int> $netByLevel */
    private static function snapshot(array $netByLevel): NetWorthSnapshot
    {
        $accounts = [];
        $statements = [];
        $id = 0;
        foreach ($netByLevel as $level => $cents) {
            ++$id;
            $accounts[] = new Account($id, "Compte $id", 1, 'Type', Level::from($level), Nature::Asset, '2020-01-01');
            $statements[$id]['2026-11'] = $cents;
        }

        return (new NetWorthCalculator($accounts, new Valuation($statements)))->snapshot(Month::fromString('2026-11'));
    }
}
