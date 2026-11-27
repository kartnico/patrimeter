<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Colour accessibility, checked on app.css in both themes (user documentation, Accessibility page):
 * - WCAG 2.2 contrasts: 4.5:1 for text (1.4.3), 3:1 for field borders, focus and graphical elements (1.4.11);
 * - pyramid levels, and budget groups (50/30/20 rule), distinct two by two for normal vision and for the three
 *   colour blindnesses (simulation by Machado, Oliveira and Fernandes, 2009), gap measured in CIE76 ΔE.
 */
final class ColorAccessibilityTest extends TestCase
{
    private const string CSS = __DIR__ . '/../../assets/styles/app.css';

    private const array LEVELS = ['residence', 'precaution', 'fonds_euros', 'actions', 'immobilier', 'exotique'];

    /** Budget groups and remaining money (flow chart, 50/30/20 rule). */
    private const array GROUPS = ['needs', 'wants', 'savings', 'available'];

    /** Minimal gap between two levels, for each simulated vision. */
    private const float MIN_DELTA_E = 12.0;

    /** Text on background: [text, background]. */
    private const array TEXT_PAIRS = [
        ['text', 'bg'], ['text', 'surface'], ['text', 'surface-2'], ['text', 'info-soft'],
        ['muted', 'bg'], ['muted', 'surface'], ['muted', 'surface-2'],
        // primary, danger and carried on their soft background: also the status pills of the goals.
        ['primary', 'surface'], ['primary', 'primary-soft'], ['primary-text', 'primary'],
        ['danger', 'surface'], ['danger', 'danger-soft'],
        ['positive', 'surface'], ['negative', 'surface'],
        ['carried', 'surface'], ['carried', 'carried-soft'],
        // Recommendations: text of the findings on their tinted background, net worth change on the budget tiles.
        ['text', 'danger-soft'], ['text', 'carried-soft'], ['text', 'primary-soft'],
        ['positive', 'surface-2'], ['negative', 'surface-2'],
        // Stars of the challenges: on the cards, on their tinted background (total, icons).
        ['star', 'surface'], ['star', 'star-soft'], ['text', 'star-soft'],
        // Défis page: thresholds on the rail of a card, captions of the two statuses on their tinted background.
        ['star', 'surface-2'], ['muted', 'star-soft'], ['muted', 'primary-soft'],
        // Family account: a member file to update, under the perimeter choice (page background).
        ['carried', 'bg'],
    ];

    /** Components and graphical elements: [element, background]. */
    private const array GRAPHIC_PAIRS = [
        ['field-border', 'surface'], ['field-border', 'surface-2'], ['field-border', 'bg'],
        ['focus', 'surface'], ['focus', 'bg'],
        ['primary', 'surface-2'],
        // Emergency savings gauge and bars of the savings payments: bar on its track.
        ['danger', 'surface-2'], ['carried', 'surface-2'], ['grp-savings', 'surface-2'],
        // Progress bars of the challenges: stars bar on its track.
        ['star', 'surface-2'],
    ];

    /** Simulation matrices (severity 1), on linear RGB. */
    private const array VISIONS = [
        'vision normale' => [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0], [0.0, 0.0, 1.0]],
        'protanopie' => [[0.152286, 1.052583, -0.204868], [0.114503, 0.786281, 0.099216], [-0.003882, -0.048116, 1.051998]],
        'deutéranopie' => [[0.367322, 0.860646, -0.227968], [0.280085, 0.672501, 0.047413], [-0.011820, 0.042940, 0.968881]],
        'tritanopie' => [[1.255528, -0.076749, -0.178779], [-0.078411, 0.930809, 0.147602], [0.004733, 0.691367, 0.303900]],
    ];

    /** @return iterable<string, array{array<string, string>}> */
    public static function themes(): iterable
    {
        $css = (string) file_get_contents(self::CSS);
        $light = self::variables(self::block($css, ':root {'));
        // Explicit dark theme; the block of the system theme must be identical.
        $darkExplicit = self::variables(self::block($css, ':root[data-theme="dark"] {'));
        $darkSystem = self::variables(self::block($css, ':root:not([data-theme="light"]) {'));
        self::assertSame($darkExplicit, $darkSystem, 'le jeu sombre est défini deux fois dans app.css : modifier les deux');

        yield 'thème clair' => [$light];
        yield 'thème sombre' => [array_merge($light, $darkExplicit)];
    }

    /** @param array<string, string> $colors */
    #[DataProvider('themes')]
    public function testTextContrast(array $colors): void
    {
        foreach (self::TEXT_PAIRS as [$text, $background]) {
            self::assertGreaterThanOrEqual(4.5, self::contrast($colors[$text], $colors[$background]), "--$text sur --$background");
        }
        foreach (self::LEVELS as $level) {
            self::assertGreaterThanOrEqual(4.5, self::contrast($colors['lvl-text'], $colors["lvl-$level"]), "texte de la pyramide sur --lvl-$level");
        }
    }

    /** @param array<string, string> $colors */
    #[DataProvider('themes')]
    public function testGraphicContrast(array $colors): void
    {
        foreach (self::GRAPHIC_PAIRS as [$element, $background]) {
            self::assertGreaterThanOrEqual(3.0, self::contrast($colors[$element], $colors[$background]), "--$element sur --$background");
        }
        foreach (self::LEVELS as $level) {
            self::assertGreaterThanOrEqual(3.0, self::contrast($colors["lvl-$level"], $colors['surface']), "aire --lvl-$level du graphique sur --surface");
        }
        foreach (self::GROUPS as $group) {
            self::assertGreaterThanOrEqual(3.0, self::contrast($colors["grp-$group"], $colors['surface']), "flux --grp-$group du budget sur --surface");
        }
    }

    /** @param array<string, string> $colors */
    #[DataProvider('themes')]
    public function testLevelsStayDistinctForColourBlindPeople(array $colors): void
    {
        self::assertDistinct($colors, 'lvl', self::LEVELS);
    }

    /** @param array<string, string> $colors */
    #[DataProvider('themes')]
    public function testBudgetGroupsStayDistinctForColourBlindPeople(array $colors): void
    {
        self::assertDistinct($colors, 'grp', self::GROUPS);
    }

    /**
     * @param array<string, string> $colors
     * @param list<string>          $names
     */
    private static function assertDistinct(array $colors, string $prefix, array $names): void
    {
        foreach (self::VISIONS as $vision => $matrix) {
            foreach ($names as $i => $a) {
                foreach (array_slice($names, $i + 1) as $b) {
                    $delta = self::deltaE(self::simulate($colors["$prefix-$a"], $matrix), self::simulate($colors["$prefix-$b"], $matrix));
                    self::assertGreaterThanOrEqual(self::MIN_DELTA_E, $delta, "$a et $b trop proches ($vision)");
                }
            }
        }
    }

    private static function block(string $css, string $selector): string
    {
        $start = strpos($css, $selector);
        self::assertNotFalse($start, "bloc $selector absent de app.css");
        $open = $start + strlen($selector);
        $end = strpos($css, '}', $open);
        self::assertNotFalse($end);

        return substr($css, $open, $end - $open);
    }

    /** @return array<string, string> */
    private static function variables(string $block): array
    {
        preg_match_all('/--([a-z0-9_-]+):\s*(#[0-9a-f]{6})\s*;/i', $block, $matches, PREG_SET_ORDER);
        $variables = [];
        foreach ($matches as $match) {
            $variables[$match[1]] = strtolower($match[2]);
        }

        return $variables;
    }

    /** @return array{float, float, float} */
    private static function linear(string $hex): array
    {
        $channel = static function (int $offset) use ($hex): float {
            $c = hexdec(substr($hex, $offset, 2)) / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return [$channel(1), $channel(3), $channel(5)];
    }

    /** @param array{float, float, float} $rgb */
    private static function luminance(array $rgb): float
    {
        return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
    }

    private static function contrast(string $a, string $b): float
    {
        $la = self::luminance(self::linear($a));
        $lb = self::luminance(self::linear($b));

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /**
     * @param list<list<float>> $matrix
     *
     * @return array{float, float, float}
     */
    private static function simulate(string $hex, array $matrix): array
    {
        $rgb = self::linear($hex);
        $out = [];
        foreach ($matrix as $row) {
            $out[] = max(0.0, min(1.0, $row[0] * $rgb[0] + $row[1] * $rgb[1] + $row[2] * $rgb[2]));
        }

        return [$out[0], $out[1], $out[2]];
    }

    /**
     * CIE76 gap between two colours in linear RGB (D65 white).
     *
     * @param array{float, float, float} $a
     * @param array{float, float, float} $b
     */
    private static function deltaE(array $a, array $b): float
    {
        $la = self::lab($a);
        $lb = self::lab($b);

        return sqrt(($la[0] - $lb[0]) ** 2 + ($la[1] - $lb[1]) ** 2 + ($la[2] - $lb[2]) ** 2);
    }

    /**
     * @param array{float, float, float} $rgb
     *
     * @return array{float, float, float}
     */
    private static function lab(array $rgb): array
    {
        $f = static fn(float $t): float => $t > 0.008856 ? $t ** (1 / 3) : 7.787 * $t + 16 / 116;
        $x = $f((0.4124 * $rgb[0] + 0.3576 * $rgb[1] + 0.1805 * $rgb[2]) / 0.95047);
        $y = $f(0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2]);
        $z = $f((0.0193 * $rgb[0] + 0.1192 * $rgb[1] + 0.9505 * $rgb[2]) / 1.08883);

        return [116 * $y - 16, 500 * ($x - $y), 200 * ($y - $z)];
    }
}
