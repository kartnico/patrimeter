<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\WealthDistribution;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WealthDistributionTest extends TestCase
{
    /** @return iterable<string, array{int, float}> */
    public static function places(): iterable
    {
        yield 'patrimoine négatif' => [-500_000, 0.0];
        yield 'patrimoine nul' => [0, 0.0];
        yield 'moitié du premier décile' => [230_000, 5.0];
        yield 'premier décile exact' => [460_000, 10.0];
        yield 'médiane exacte (148 100 €)' => [14_810_000, 50.0];
        yield 'entre médiane et 6e décile' => [18_615_000, 55.0];
        yield 'neuvième décile (750 400 €)' => [75_040_000, 90.0];
        yield 'entre 95e et 99e centiles' => [194_020_000, 97.0];
        yield 'au-delà du 99e centile' => [500_000_000, 99.0];
    }

    #[DataProvider('places')]
    public function testPlaceIsInterpolatedBetweenPublishedThresholds(int $netCents, float $expected): void
    {
        self::assertEqualsWithDelta($expected, WealthDistribution::percentile($netCents), 0.001);
    }

    public function testRankLabels(): void
    {
        $median = WealthDistribution::rank(14_810_000);
        self::assertSame(50, $median->percent());
        self::assertSame(6, $median->decile(), '50 % des ménages en dessous : sixième décile');
        self::assertFalse($median->isTopPercent());

        $poor = WealthDistribution::rank(-1);
        self::assertSame(1, $poor->percent(), 'jamais « 0 % »');
        self::assertSame(1, $poor->decile());
        self::assertTrue($poor->isBottomDecile());

        $rich = WealthDistribution::rank(300_000_000);
        self::assertSame(99, $rich->percent());
        self::assertSame(10, $rich->decile());
        self::assertTrue($rich->isTopPercent());
    }

    public function testThresholdsAreIncreasingAndSourced(): void
    {
        $previous = 0;
        foreach (WealthDistribution::QUANTILES as $cents) {
            self::assertGreaterThan($previous, $cents);
            $previous = $cents;
        }
        self::assertStringStartsWith('https://www.insee.fr/', WealthDistribution::SOURCE_URL);
    }
}
