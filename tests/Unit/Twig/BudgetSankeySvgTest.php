<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Twig;

use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Month;
use Patrimeter\Twig\BudgetSankeySvg;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\IdentityTranslator;

/** Budget flows in SVG: nodes, proportional flows, labels, accessibility, without inline style. */
final class BudgetSankeySvgTest extends TestCase
{
    public function testRendersIncomesBudgetGroupsAndCategories(): void
    {
        $svg = self::render(self::overview([1 => 300_000, 2 => 100_000], [1 => 150_000, 2 => 50_000, 3 => 40_000, 4 => 0]));

        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($svg), 'SVG bien formé');
        $nodes = self::classes($doc, 'rect', 'sankey-node');
        // 2 incomes, the budget, needs and wants (empty savings), the remaining money, 3 categories (Holidays, empty, is not there).
        self::assertCount(9, $nodes);
        self::assertSame(2, self::withClass($nodes, 'is-income'));
        self::assertSame(1, self::withClass($nodes, 'is-available'));
        self::assertSame(0, self::withClass($nodes, 'is-savings'));
        self::assertSame(0, self::withClass($nodes, 'is-overrun'));
        self::assertCount(8, self::classes($doc, 'path', 'sankey-link'), '2 + 3 + 3 flux');
        self::assertStringContainsString('Revenus de Alex <b>', html_entity_decode($svg), 'prénom échappé, puis restitué');
        self::assertStringContainsString('Logement', $svg);
        self::assertStringNotContainsString('Vacances', $svg);
        self::assertStringContainsString('Sam · ', html_entity_decode($svg), 'catégorie propre : adulte et montant en seconde ligne');
        self::assertStringContainsString('(cible 50 %)', $svg);
        self::assertStringContainsString('role="img"', $svg);
        self::assertStringContainsString('<desc id="sankey-desc">', $svg);
        self::assertStringNotContainsString('style=', $svg, 'aucun style inline (CSP)');
        self::assertStringNotContainsString('<b>', $svg, 'texte échappé');
    }

    public function testFlowsAreProportionalToTheirAmount(): void
    {
        $svg = self::render(self::overview([1 => 300_000, 2 => 100_000], [1 => 150_000, 2 => 50_000, 3 => 40_000, 4 => 0]));

        $doc = new \DOMDocument();
        $doc->loadXML($svg);
        $widths = [];
        foreach (self::classes($doc, 'path', 'sankey-link') as $path) {
            $widths[$path->getAttribute('class')][] = (float) $path->getAttribute('stroke-width');
        }
        // Incomes 300,000 and 100,000: flows in a ratio of 3 to 1.
        self::assertEqualsWithDelta(3.0, $widths['sankey-link is-income'][0] / $widths['sankey-link is-income'][1], 0.02);
        // Needs (Housing 150,000 + Groceries 40,000) against wants (Sam's Leisure 50,000).
        self::assertEqualsWithDelta(190_000 / 50_000, $widths['sankey-link is-needs'][0] / $widths['sankey-link is-wants'][0], 0.02);
    }

    public function testAnOverspendFeedsTheBudgetAndLeavesNothingAvailable(): void
    {
        $svg = self::render(self::overview([1 => 100_000], [1 => 150_000, 2 => 0, 3 => 0, 4 => 0]));

        self::assertStringContainsString('sankey-node is-overrun', $svg);
        self::assertStringNotContainsString('is-available', $svg);
        self::assertStringContainsString('Dépassement', $svg);
    }

    public function testNothingToDrawWithoutIncomeNorSpending(): void
    {
        self::assertSame('', self::render(self::overview([], [])));
    }

    public function testLongNamesAreShortenedButKeptInTheTooltip(): void
    {
        $household = new Household([1 => 'Alex']);
        $long = 'Une catégorie au nom vraiment très long pour le graphique';
        $overview = new BudgetOverview($household, new MonthlyBudget(Month::fromString('2026-11'), [1 => 100_000], [1 => 10_000]), [new BudgetCategory(1, $long, BudgetGroup::Needs)], [new BudgetLine(1, 1, 'Poste')]);

        $svg = self::render($overview);

        self::assertStringContainsString('Une catégorie au nom vr…', $svg);
        self::assertStringContainsString('<title>' . $long . ' : ', $svg);
    }

    /**
     * Couple: Housing (shared, needs), Sam's Leisure (Sam's own, wants), Groceries (shared, needs), Holidays (shared,
     * savings).
     *
     * @param array<int, int> $incomes
     * @param array<int, int> $amounts item => cents
     */
    private static function overview(array $incomes, array $amounts): BudgetOverview
    {
        $household = new Household([1 => 'Alex <b>', 2 => 'Sam']);
        $categories = [
            new BudgetCategory(1, 'Logement', BudgetGroup::Needs),
            new BudgetCategory(2, 'Loisirs de Sam', BudgetGroup::Wants, 2),
            new BudgetCategory(3, 'Courses', BudgetGroup::Needs),
            new BudgetCategory(4, 'Vacances', BudgetGroup::Savings),
        ];
        $lines = [new BudgetLine(1, 1, 'Loyer'), new BudgetLine(2, 2, 'Sport'), new BudgetLine(3, 3, 'Courses'), new BudgetLine(4, 4, 'Vacances')];

        return new BudgetOverview($household, new MonthlyBudget(Month::fromString('2026-11'), $incomes, $amounts), $categories, $lines);
    }

    private static function render(BudgetOverview $overview): string
    {
        $translator = new IdentityTranslator();
        $translator->setLocale('fr');

        return (new BudgetSankeySvg($translator))->render($overview);
    }

    /** @return list<\DOMElement> $tag elements whose class contains $class */
    private static function classes(\DOMDocument $doc, string $tag, string $class): array
    {
        $found = [];
        foreach ($doc->getElementsByTagName($tag) as $element) {
            if (str_contains(' ' . $element->getAttribute('class') . ' ', " $class ")) {
                $found[] = $element;
            }
        }

        return $found;
    }

    /** @param list<\DOMElement> $elements */
    private static function withClass(array $elements, string $class): int
    {
        return count(array_filter($elements, static fn(\DOMElement $element): bool => str_contains($element->getAttribute('class'), $class)));
    }
}
