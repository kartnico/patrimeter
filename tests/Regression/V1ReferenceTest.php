<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Regression;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\NetWorthSnapshot;
use Patrimeter\Domain\Valuation;
use Patrimeter\Tests\Support\ReferenceDataset;
use Patrimeter\Twig\PyramidSvg;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/**
 * Calculation regression: the current code must reproduce exactly the results of v1 (net worth, levels, shares,
 * detail of the values entered and carried over, pyramid).
 *
 * @phpstan-import-type ExpectedMonth from ReferenceDataset
 */
final class V1ReferenceTest extends TestCase
{
    private static ?NetWorthCalculator $calculator = null;

    /** @return iterable<string, array{ExpectedMonth}> */
    public static function months(): iterable
    {
        foreach (ReferenceDataset::expected() as $expected) {
            yield $expected['month'] => [$expected];
        }
    }

    /** @param ExpectedMonth $expected */
    #[DataProvider('months')]
    public function testSnapshotMatchesV1(array $expected): void
    {
        self::assertSame($expected, self::summary(self::calculator()->snapshot(Month::fromString($expected['month']))));
    }

    /** @return iterable<string, array{string}> */
    public static function pyramidMonths(): iterable
    {
        yield 'patrimoine négatif' => ['2022-12'];
        yield 'niveau immobilier négatif' => ['2023-10'];
        yield 'fin de période' => ['2025-12'];
    }

    #[DataProvider('pyramidMonths')]
    public function testPyramidMatchesV1(string $month): void
    {
        $svg = (new PyramidSvg(self::frenchTranslator()))->render(self::calculator()->snapshot(Month::fromString($month)));

        self::assertSame(ReferenceDataset::pyramid($month), $svg);
    }

    private static function calculator(): NetWorthCalculator
    {
        if (self::$calculator === null) {
            $accounts = array_map(
                static fn(array $a): Account => new Account($a['id'], $a['name'], 0, $a['type'], Level::from($a['level']), Nature::from($a['nature']), $a['openedOn'], $a['closedOn']),
                ReferenceDataset::accounts(),
            );
            $statements = [];
            foreach (ReferenceDataset::statements() as $statement) {
                $statements[$statement['account']][$statement['month']] = $statement['cents'];
            }
            self::$calculator = new NetWorthCalculator($accounts, new Valuation($statements));
        }

        return self::$calculator;
    }

    /**
     * Same shape as v1-expected.json (shares rounded as during the generation).
     *
     * @return ExpectedMonth
     */
    public static function summary(NetWorthSnapshot $snapshot): array
    {
        $levels = [];
        foreach (Level::ordered() as $level) {
            $total = $snapshot->level($level);
            $share = $snapshot->share($level);
            $levels[$level->value] = [
                'assets' => $total->assets,
                'liabilities' => $total->liabilities,
                'net' => $total->net(),
                'share' => $share === null ? null : self::roundShare($share),
                'lines' => array_map(static fn(array $line): array => [
                    'account' => $line['account']->id,
                    'cents' => $line['value']->cents,
                    'sourceMonth' => $line['value']->sourceMonth->toString(),
                    'carried' => $line['value']->carried,
                ], $total->lines),
            ];
        }

        return [
            'month' => $snapshot->month->toString(),
            'hasData' => $snapshot->hasData(),
            'net' => $snapshot->net(),
            'assets' => $snapshot->assets(),
            'liabilities' => $snapshot->liabilities(),
            'enteredCount' => $snapshot->enteredCount,
            'carriedCount' => $snapshot->carriedCount,
            'levels' => $levels,
        ];
    }

    /** Rounded to 6 decimals; a whole share stays an integer, as in the generated JSON. */
    private static function roundShare(float $share): float|int
    {
        $rounded = round($share, 6);

        return $rounded === floor($rounded) ? (int) $rounded : $rounded;
    }

    private static function frenchTranslator(): IdentityTranslator
    {
        $translator = new IdentityTranslator();
        $translator->setLocale('fr');

        return $translator;
    }
}
