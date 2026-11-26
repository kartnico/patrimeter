<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Twig;

use Patrimeter\Domain\Budget\PrecautionCoverage;
use Patrimeter\Twig\PrecautionGaugeSvg;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/** Emergency savings gauge: zones, bar, marker, ticks, accessibility, without inline style. */
final class PrecautionGaugeSvgTest extends TestCase
{
    public function testGaugeBelowTheTarget(): void
    {
        // 2.5 months of spending for a target of 4 months: scale of 8 months.
        $svg = self::render(new PrecautionCoverage(500_000, 200_000, 4));

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($svg), 'SVG bien formé');
        self::assertStringContainsString('class="precaution-gauge is-low"', $svg);
        self::assertSame(['is-critical', 'is-low', 'is-reached'], self::zoneClasses($doc));
        $fill = self::first($doc, 'rect', 'gauge-fill');
        self::assertSame('gauge-fill is-low', $fill->getAttribute('class'));
        // 300 units for 8 months: 2.5 months = 93.75 units.
        self::assertEqualsWithDelta(93.75, (float) $fill->getAttribute('width'), 0.1);
        self::assertSame('160.0', self::first($doc, 'line', 'gauge-target')->getAttribute('x1'), 'cible au milieu de l\'échelle');
        self::assertSame(['2,5 mois', '0', 'cible : 4 mois', '8 mois'], self::texts($doc));
        self::assertStringContainsString('role="img"', $svg);
        self::assertStringContainsString('aria-label="Épargne de précaution : 2,5 mois de dépenses. Cible : 4 mois."', $svg);
        self::assertStringNotContainsString('style=', $svg, 'aucun style inline (CSP)');
    }

    public function testSurplusFillsTheWholeScale(): void
    {
        $svg = self::render(new PrecautionCoverage(1_300_000, 100_000, 3));

        $doc = new \DOMDocument();
        $doc->loadXML($svg);
        self::assertSame('gauge-fill is-surplus', self::first($doc, 'rect', 'gauge-fill')->getAttribute('class'));
        self::assertSame('300.0', self::first($doc, 'rect', 'gauge-fill')->getAttribute('width'));
        self::assertSame(['13 mois', '0', 'cible : 3 mois', '6 mois +'], self::texts($doc), 'au-delà de l\'échelle');
    }

    public function testWithoutTargetTheScaleFollowsTheSavings(): void
    {
        $svg = self::render(new PrecautionCoverage(1_000_000, 100_000, 0));

        $doc = new \DOMDocument();
        $doc->loadXML($svg);
        self::assertSame(['is-critical', 'is-neutral'], self::zoneClasses($doc));
        self::assertSame(0, $doc->getElementsByTagName('line')->length, 'pas de cible');
        self::assertSame(['10 mois', '0', '10 mois'], self::texts($doc));
    }

    public function testNothingToDrawWithoutExpenses(): void
    {
        self::assertSame('', self::render(new PrecautionCoverage(500_000, 0, 3)));
    }

    public function testMonthsInEnglish(): void
    {
        $translator = new IdentityTranslator();
        $translator->setLocale('en');
        $gauge = new PrecautionGaugeSvg($translator);

        self::assertSame('2.5 mois', $gauge->months(2.5));
        self::assertSame('3 mois', $gauge->months(3.0));
        self::assertSame('1 mois', $gauge->months(1.04), 'arrondi à une décimale');
    }

    private static function render(PrecautionCoverage $coverage): string
    {
        $translator = new IdentityTranslator();
        $translator->setLocale('fr');

        return (new PrecautionGaugeSvg($translator))->render($coverage);
    }

    /** @return list<string> status class of each zone of the track */
    private static function zoneClasses(\DOMDocument $doc): array
    {
        $classes = [];
        foreach ($doc->getElementsByTagName('rect') as $rect) {
            if (str_starts_with($rect->getAttribute('class'), 'gauge-zone ')) {
                $classes[] = substr($rect->getAttribute('class'), strlen('gauge-zone '));
            }
        }

        return $classes;
    }

    private static function first(\DOMDocument $doc, string $tag, string $class): \DOMElement
    {
        foreach ($doc->getElementsByTagName($tag) as $element) {
            if (str_starts_with($element->getAttribute('class'), $class)) {
                return $element;
            }
        }
        self::fail("Aucun élément $tag.$class");
    }

    /** @return list<string> */
    private static function texts(\DOMDocument $doc): array
    {
        $texts = [];
        foreach ($doc->getElementsByTagName('text') as $text) {
            $texts[] = str_replace("\u{202F}", ' ', $text->textContent);
        }

        return $texts;
    }
}
