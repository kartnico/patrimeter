<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetOverview;
use Patrimeter\Domain\Money;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Flow of the household budget in SVG (Sankey diagram, without a library nor inline style: colours through CSS
 * classes).
 *
 * Four columns, from left to right: each adult's incomes (and the overrun if the budget exceeds the incomes), the
 * household budget, the groups of the 50/30/20 rule (needs, wants, savings, available) with their share of the incomes
 * and their target, the categories. The thickness of a flow is proportional to its amount. Each label, on a solid
 * background, sits to the right of its node (over the flows leaving it), the budget's above it; labels too close
 * together are moved apart.
 */
final class BudgetSankeySvg
{
    private const float WIDTH = 960.0;
    private const float TOP = 34.0;
    private const float BOTTOM = 12.0;
    private const float NODE = 10.0;
    /** Abscissa of the nodes of each column; on the right, the margin of the category labels. */
    private const array COLUMNS = [0.0, 250.0, 470.0, 730.0];
    private const float PAD = 14.0;
    /** Height reserved per node of the fullest column (two lines of label). */
    private const float ROW = 42.0;
    private const float MIN_HEIGHT = 250.0;
    private const float LABEL_GAP = 34.0;
    private const float LABEL_OFFSET = 8.0;
    private const int NAME_LENGTH = 24;

    public function __construct(private readonly TranslatorInterface $translator) {}

    /** SVG of the month; empty string if the budget has neither incomes nor spending. */
    public function render(BudgetOverview $overview): string
    {
        $total = max($overview->income, $overview->spending());
        if ($total === 0) {
            return '';
        }
        [$nodes, $links] = $this->graph($overview, $total);

        $sums = [];
        $counts = [];
        foreach ($nodes as $node) {
            $sums[$node['column']] = ($sums[$node['column']] ?? 0) + $node['value'];
            $counts[$node['column']] = ($counts[$node['column']] ?? 0) + 1;
        }
        $innerHeight = max(self::MIN_HEIGHT, self::ROW * (float) max(1, ...array_values($counts)));
        $scale = INF;
        foreach ($sums as $column => $sum) {
            $scale = min($scale, ($innerHeight - (float) ($counts[$column] - 1) * self::PAD) / (float) $sum);
        }

        // Nodes stacked from top to bottom, each column centred vertically: position and height of each.
        $cursor = [];
        foreach ($sums as $column => $sum) {
            $cursor[$column] = self::TOP + ($innerHeight - ((float) $sum * $scale + (float) ($counts[$column] - 1) * self::PAD)) / 2.0;
        }
        /** @var array<string, array{y: float, height: float}> $boxes */
        $boxes = [];
        foreach ($nodes as $key => $node) {
            $height = (float) $node['value'] * $scale;
            $boxes[$key] = ['y' => $cursor[$node['column']], 'height' => $height];
            $cursor[$node['column']] += $height + self::PAD;
        }

        // Flows: departures and arrivals stacked in the order of creation (already from top to bottom).
        $out = [];
        $in = [];
        $paths = [];
        foreach ($links as $link) {
            $source = $nodes[$link['from']];
            $target = $nodes[$link['to']];
            $thickness = (float) $link['value'] * $scale;
            $y0 = $boxes[$link['from']]['y'] + ($out[$link['from']] ?? 0.0) + $thickness / 2.0;
            $y1 = $boxes[$link['to']]['y'] + ($in[$link['to']] ?? 0.0) + $thickness / 2.0;
            $out[$link['from']] = ($out[$link['from']] ?? 0.0) + $thickness;
            $in[$link['to']] = ($in[$link['to']] ?? 0.0) + $thickness;
            $x0 = self::COLUMNS[$source['column']] + self::NODE;
            $x1 = self::COLUMNS[$target['column']];
            $middle = ($x0 + $x1) / 2.0;
            $paths[] = sprintf(
                '<path class="sankey-link %s" d="M%.1f %.1fC%.1f %.1f %.1f %.1f %.1f %.1f" stroke-width="%.1f"/>',
                $link['class'],
                $x0,
                $y0,
                $middle,
                $y0,
                $middle,
                $y1,
                $x1,
                $y1,
                max(1.0, $thickness),
            );
        }

        $shapes = [];
        foreach ($nodes as $key => $node) {
            $shapes[] = sprintf(
                '<rect class="sankey-node %s" x="%.1f" y="%.1f" width="%.1f" height="%.1f"><title>%s</title></rect>',
                $node['class'],
                self::COLUMNS[$node['column']],
                $boxes[$key]['y'],
                self::NODE,
                max(1.0, $boxes[$key]['height']),
                self::escape($node['title']),
            );
        }

        $height = self::TOP + $innerHeight + self::BOTTOM;
        $labels = [
            ...$this->columnLabels($nodes, $boxes, 0, $height),
            ...$this->columnLabels($nodes, $boxes, 2, $height),
            ...$this->columnLabels($nodes, $boxes, 3, $height),
        ];
        // Household budget: one line above its node.
        $budget = $nodes['budget'];
        $labels[] = $this->label(self::COLUMNS[1] + self::NODE / 2.0, $boxes['budget']['y'] - 12.0, 'middle', $budget['name'] . ' · ' . $budget['detail'], null);

        $locale = $this->translator->getLocale();
        $title = $this->translator->trans('Répartition des revenus du foyer, %month%', ['%month%' => $overview->budget->month->label($locale)]);

        return sprintf(
            '<svg class="sankey" viewBox="0 0 %d %d" role="img" aria-labelledby="sankey-title sankey-desc" xmlns="http://www.w3.org/2000/svg">'
            . '<title id="sankey-title">%s</title><desc id="sankey-desc">%s</desc><g class="sankey-links">%s</g><g class="sankey-nodes">%s</g>%s</svg>',
            self::WIDTH,
            (int) ceil($height),
            self::escape($title),
            self::escape($this->description($overview)),
            implode('', $paths),
            implode('', $shapes),
            implode('', $labels),
        );
    }

    /**
     * Nodes (by key, in display order) and flows.
     *
     * @return array{array<string, array{column: int, name: string, detail: string, title: string, value: int, class: string}>, list<array{from: string, to: string, value: int, class: string}>}
     */
    private function graph(BudgetOverview $overview, int $total): array
    {
        $locale = $this->translator->getLocale();
        $household = $overview->household;
        $nodes = [];
        $links = [];
        $add = static function (string $key, int $column, string $name, string $detail, int $value, string $class) use (&$nodes): void {
            $nodes[$key] = ['column' => $column, 'name' => $name, 'detail' => $detail, 'title' => $name . ' : ' . $detail, 'value' => $value, 'class' => $class];
        };

        $childIncomes = array_filter(
            array_map($overview->budget->childIncome(...), array_combine(array_keys($household->children), array_keys($household->children))),
            static fn(int $cents): bool => $cents > 0,
        );
        foreach ($household->adultIds() as $adult) {
            $income = $overview->budget->income($adult);
            if ($income > 0) {
                $name = $household->isCouple() || $childIncomes !== []
                    ? $this->translator->trans('Revenus de %name%', ['%name%' => $household->adultName($adult)])
                    : $this->translator->trans('Revenus');
                $add("income-$adult", 0, $name, Money::formatRounded($income, $locale), $income, 'is-income');
                $links[] = ['from' => "income-$adult", 'to' => 'budget', 'value' => $income, 'class' => 'is-income'];
            }
        }
        // Incomes of the children of working age.
        foreach ($childIncomes as $child => $income) {
            $name = $this->translator->trans('Revenus de %name%', ['%name%' => (string) $household->childName($child)]);
            $add("income-child-$child", 0, $name, Money::formatRounded($income, $locale), $income, 'is-income');
            $links[] = ['from' => "income-child-$child", 'to' => 'budget', 'value' => $income, 'class' => 'is-income'];
        }
        $overrun = $overview->spending() - $overview->income;
        if ($overrun > 0) {
            $add('overrun', 0, $this->translator->trans('Dépassement'), Money::formatRounded($overrun, $locale), $overrun, 'is-overrun');
            $links[] = ['from' => 'overrun', 'to' => 'budget', 'value' => $overrun, 'class' => 'is-overrun'];
        }
        $add('budget', 1, $this->translator->trans('Budget du foyer'), Money::formatRounded($total, $locale), $total, 'is-budget');

        foreach (BudgetGroup::cases() as $group) {
            $cents = $overview->groupTotal($group);
            if ($cents > 0) {
                $share = Money::formatPercent($overview->shareOfIncome($cents), 1, $locale);
                $detail = $this->translator->trans('%amount% · %share% (cible %target% %)', [
                    '%amount%' => Money::formatRounded($cents, $locale),
                    '%share%' => $share,
                    '%target%' => $group->target(),
                ]);
                $add('group-' . $group->value, 2, $this->translator->trans($group->label()), $detail, $cents, 'is-' . $group->value);
                $links[] = ['from' => 'budget', 'to' => 'group-' . $group->value, 'value' => $cents, 'class' => 'is-' . $group->value];
            }
        }
        $available = $overview->available();
        if ($available > 0) {
            $detail = Money::formatRounded($available, $locale) . ' · ' . Money::formatPercent($overview->shareOfIncome($available), 1, $locale);
            $add('available', 2, $this->translator->trans('Disponible'), $detail, $available, 'is-available');
            $links[] = ['from' => 'budget', 'to' => 'available', 'value' => $available, 'class' => 'is-available'];
        }

        foreach (self::sortedCategories($overview) as $category) {
            $cents = $overview->categoryTotal($category->id);
            $amount = Money::formatRounded($cents, $locale);
            $detail = $category->adult === null ? $amount : $household->adultName($category->adult) . ' · ' . $amount;
            $add('category-' . $category->id, 3, $category->name, $detail, $cents, 'is-' . $category->group->value);
            $links[] = ['from' => 'group-' . $category->group->value, 'to' => 'category-' . $category->id, 'value' => $cents, 'class' => 'is-' . $category->group->value];
        }

        return [$nodes, $links];
    }

    /**
     * Non-empty categories, by group (in the groups' order) then from the largest to the smallest: their flows leave
     * their group without crossing.
     *
     * @return list<BudgetCategory>
     */
    private static function sortedCategories(BudgetOverview $overview): array
    {
        $order = array_flip(BudgetGroup::values());
        $categories = array_values(array_filter($overview->categories, static fn(BudgetCategory $category): bool => $overview->categoryTotal($category->id) > 0));
        usort($categories, static fn(BudgetCategory $a, BudgetCategory $b): int => [$order[$a->group->value], $overview->categoryTotal($b->id), $a->id]
            <=> [$order[$b->group->value], $overview->categoryTotal($a->id), $b->id]);

        return $categories;
    }

    /**
     * Labels of a column, to the right of their node, at least LABEL_GAP apart and kept inside the drawing.
     *
     * @param array<string, array{column: int, name: string, detail: string, title: string, value: int, class: string}> $nodes
     * @param array<string, array{y: float, height: float}>                                                                $boxes
     *
     * @return list<string>
     */
    private function columnLabels(array $nodes, array $boxes, int $column, float $height): array
    {
        $texts = [];
        $ys = [];
        foreach ($nodes as $key => $node) {
            if ($node['column'] === $column) {
                $texts[] = [$node['name'], $node['detail']];
                $ys[] = $boxes[$key]['y'] + $boxes[$key]['height'] / 2.0 - 4.0;
            }
        }
        // Moves down, then moves back up what would overflow the drawing.
        $count = count($ys);
        for ($i = 1; $i < $count; ++$i) {
            $ys[$i] = max($ys[$i], $ys[$i - 1] + self::LABEL_GAP);
        }
        $limit = $height - self::BOTTOM - 22.0;
        for ($i = $count - 1; $i >= 0; --$i) {
            $ceiling = $i === $count - 1 ? $limit : $ys[$i + 1] - self::LABEL_GAP;
            $ys[$i] = max(self::TOP / 2.0 + 12.0, min($ys[$i], $ceiling));
        }

        $x = self::COLUMNS[$column] + self::NODE + self::LABEL_OFFSET;
        $labels = [];
        foreach ($texts as $index => [$name, $detail]) {
            $labels[] = $this->label($x, $ys[$index], 'start', $name, $detail);
        }

        return $labels;
    }

    /** Label on a solid background: name (shortened if needed) and amount on a second line, or a single full line. */
    private function label(float $x, float $y, string $anchor, string $name, ?string $detail): string
    {
        $short = $detail !== null && mb_strlen($name) > self::NAME_LENGTH ? rtrim(mb_substr($name, 0, self::NAME_LENGTH - 1)) . '…' : $name;
        // Estimated width (the server does not measure the text, in bold for the name): a little wide rather than too narrow.
        $width = max((float) mb_strlen($short) * 8.0, $detail === null ? 0.0 : (float) mb_strlen($detail) * 7.0) + 10.0;
        $left = $anchor === 'middle' ? $x - $width / 2.0 : $x - 5.0;
        $lines = $detail === null ? 1 : 2;

        return sprintf(
            '<g class="sankey-label"><rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="4"/><text x="%.1f" y="%.1f" text-anchor="%s"><tspan class="sankey-name">%s</tspan>%s</text></g>',
            $left,
            $y - 14.0,
            $width,
            $lines === 1 ? 20.0 : 35.0,
            $x,
            $y,
            $anchor,
            self::escape($short),
            $detail === null ? '' : sprintf('<tspan class="sankey-detail" x="%.1f" dy="15">%s</tspan>', $x, self::escape($detail)),
        );
    }

    /** Summary for screen readers; the details are in the page's tables. */
    private function description(BudgetOverview $overview): string
    {
        $locale = $this->translator->getLocale();
        $parts = [];
        foreach (BudgetGroup::cases() as $group) {
            $cents = $overview->groupTotal($group);
            $parts[] = $this->translator->trans($group->label()) . ' : ' . Money::formatRounded($cents, $locale)
                . ' (' . Money::formatPercent($overview->shareOfIncome($cents), 1, $locale) . ')';
        }
        $parts[] = $this->translator->trans('Disponible') . ' : ' . Money::formatRounded($overview->available(), $locale);

        return $this->translator->trans('Revenus du foyer : %amount%.', ['%amount%' => Money::formatRounded($overview->income, $locale)]) . ' ' . implode(' ; ', $parts) . '.';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
