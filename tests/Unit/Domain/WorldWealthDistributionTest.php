<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\WorldWealthDistribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WorldWealthDistributionTest extends TestCase
{
    /** @return iterable<string, array{int, float}> */
    public static function places(): iterable
    {
        yield 'patrimoine négatif' => [-500_000, 0.0];
        yield 'patrimoine nul' => [0, 0.0];
        // $10,000 at the rate of $1.1750: €8,510.64.
        yield 'moitié du premier seuil (en ligne droite)' => [425_532, 21.05];
        yield 'premier seuil exact' => [851_064, 42.1];
        // Logarithmic middle of the $10,000 to $100,000 band: $31,623, i.e. €26,913.36.
        yield 'milieu logarithmique de la deuxième tranche' => [2_691_336, 62.65];
        yield '100 000 $ (85 106,38 €)' => [8_510_638, 83.2];
        yield 'un million de dollars' => [85_106_383, 98.5];
        yield 'au-delà de 5 millions de dollars' => [1_000_000_000, 99.8];
    }

    #[DataProvider('places')]
    public function testPlaceIsInterpolatedWithinPublishedBands(int $netCents, float $expected): void
    {
        self::assertEqualsWithDelta($expected, WorldWealthDistribution::percentile($netCents), 0.01);
    }

    public function testThresholdsAreConvertedToEuros(): void
    {
        $rows = WorldWealthDistribution::thresholds();

        self::assertSame(['percent' => 42.1, 'usd' => 10_000, 'cents' => 851_064], $rows[0]);
        self::assertSame(['percent' => 98.5, 'usd' => 1_000_000, 'cents' => 85_106_383], $rows[2]);
        $previous = 0;
        foreach ($rows as $row) {
            self::assertGreaterThan($previous, $row['cents']);
            $previous = $row['cents'];
        }
        self::assertStringStartsWith('https://www.ubs.com/', WorldWealthDistribution::SOURCE_URL);
    }

    public function testRank(): void
    {
        self::assertSame(83, WorldWealthDistribution::rank(8_510_638)->percent());
        self::assertSame(99, WorldWealthDistribution::rank(100_000_000)->percent(), '1 million d\'euros : environ 98,6 %');
        self::assertFalse(WorldWealthDistribution::rank(100_000_000)->isTopPercent());
        self::assertTrue(WorldWealthDistribution::rank(1_000_000_000)->isTopPercent(), '10 millions d\'euros : au-delà de 99 %');
        self::assertFalse(WorldWealthDistribution::rank(8_510_638)->isTopPercent());
    }
}
