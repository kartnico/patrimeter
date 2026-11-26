<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Twig;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\Valuation;
use Patrimeter\Twig\PyramidSvg;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

final class PyramidSvgTest extends TestCase
{
    public function testRendersAllLevelsAsValidSvg(): void
    {
        $accounts = [
            new Account(1, 'Maison <&>', 1, 'RP', Level::Residence, Nature::Asset, '2020-01-01'),
            new Account(2, 'Prêt', 1, 'Crédit', Level::Residence, Nature::Liability, '2020-01-01'),
            new Account(3, 'PEA', 1, 'PEA', Level::Actions, Nature::Asset, '2020-01-01'),
        ];
        $valuation = new Valuation([1 => ['2026-01' => 30_000_000], 2 => ['2026-01' => 10_000_000], 3 => ['2026-01' => 5_000_000]]);
        $snapshot = (new NetWorthCalculator($accounts, $valuation))->snapshot(Month::fromString('2026-01'));

        $svg = (new PyramidSvg(self::frenchTranslator()))->render($snapshot);

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($svg), 'SVG bien formé');
        self::assertSame(6, $doc->getElementsByTagName('polygon')->length);
        self::assertStringContainsString("200\u{202F}000\u{00A0}€", $svg);
        self::assertStringContainsString("80,0\u{00A0}%", $svg);
        self::assertStringContainsString('pyr-exotique is-empty', $svg);
        self::assertStringNotContainsString('style=', $svg, 'aucun style inline (CSP)');
        self::assertStringNotContainsString('<&>', $svg);
    }

    public function testWithoutAmountsOnlySharesAreShown(): void
    {
        $accounts = [
            new Account(1, 'Maison', 1, 'RP', Level::Residence, Nature::Asset, '2020-01-01'),
            new Account(3, 'PEA', 1, 'PEA', Level::Actions, Nature::Asset, '2020-01-01'),
        ];
        $valuation = new Valuation([1 => ['2026-01' => 30_000_000], 3 => ['2026-01' => 10_000_000]]);
        $snapshot = (new NetWorthCalculator($accounts, $valuation))->snapshot(Month::fromString('2026-01'));

        $svg = (new PyramidSvg(self::frenchTranslator()))->render($snapshot, amounts: false);

        self::assertTrue((new \DOMDocument())->loadXML($svg), 'SVG bien formé');
        self::assertStringContainsString("75,0\u{00A0}%", $svg);
        self::assertStringContainsString("25,0\u{00A0}%", $svg);
        self::assertStringNotContainsString('€', $svg, 'aucun montant, ni dans les textes ni dans les titres');
    }

    private static function frenchTranslator(): IdentityTranslator
    {
        $translator = new IdentityTranslator();
        $translator->setLocale('fr');

        return $translator;
    }
}
