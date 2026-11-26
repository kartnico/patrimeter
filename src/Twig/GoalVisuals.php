<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Domain\Goal\GoalProgress;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Visuals of a savings goal, prepared on the server (no inline style: colours through CSS classes).
 *
 * - goal_gauge: ring of the share reached, its number in the middle, and a mark where the planned trajectory stands
 *   this month; the status is written next to it, the colour only repeats it.
 * - goal_chart: data of the projection chart drawn by goals.js (Chart.js) from the start to the target month: the value
 *   reached month after month (solid line over a shaded area), the planned trajectory (dashed), the projection at the
 *   current pace (dotted) and the target (thin line); its text description, shown under the chart, repeats the figures
 *   (screen readers, pages without JavaScript).
 */
final class GoalVisuals extends AbstractExtension
{
    private const float CENTER = 60.0;
    private const float RADIUS = 50.0;
    private const float MARK_INNER = 40.0;
    private const float MARK_OUTER = 60.0;

    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requests,
    ) {}

    #[\Override]
    public function getFunctions(): array
    {
        return [
            new TwigFunction('goal_gauge', $this->gauge(...), ['is_safe' => ['html']]),
            new TwigFunction('goal_chart', $this->chart(...)),
        ];
    }

    public function gauge(GoalProgress $progress): string
    {
        $percent = $progress->percent();
        $expected = $progress->expectedPercent();
        $circumference = 2.0 * M_PI * self::RADIUS;
        $circle = sprintf('cx="%1$.0f" cy="%1$.0f" r="%2$.0f"', self::CENTER, self::RADIUS);
        $svg = ["<circle class=\"goal-gauge-track\" $circle/>"];
        if ($percent > 0) {
            // Starts at the top (rotation of a quarter turn), clockwise.
            $svg[] = sprintf(
                '<circle class="goal-gauge-fill" %s stroke-dasharray="%.2f %.2f" transform="rotate(-90 %.0f %4$.0f)"/>',
                $circle,
                $circumference * (float) $percent / 100.0,
                $circumference,
                self::CENTER,
            );
        }
        if ($progress->status->value !== 'reached') {
            $angle = deg2rad(360.0 * (float) $expected / 100.0 - 90.0);
            $svg[] = sprintf(
                '<line class="goal-gauge-mark" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>',
                self::CENTER + self::MARK_INNER * cos($angle),
                self::CENTER + self::MARK_INNER * sin($angle),
                self::CENTER + self::MARK_OUTER * cos($angle),
                self::CENTER + self::MARK_OUTER * sin($angle),
            );
        }
        $svg[] = sprintf('<text class="goal-gauge-value" x="60" y="62" text-anchor="middle">%s</text>', self::escape($this->translator->trans('%percent% %', ['%percent%' => $percent])));
        $svg[] = sprintf('<text class="goal-gauge-caption" x="60" y="80" text-anchor="middle">%s</text>', self::escape($this->translator->trans('atteint')));
        $label = $progress->status->value === 'reached'
            ? $this->translator->trans('%percent% % de l\'objectif atteint.', ['%percent%' => $percent])
            : $this->translator->trans('%percent% % de l\'objectif atteint ; la trajectoire prévue en donne %expected% % ce mois-ci (repère sur l\'anneau).', ['%percent%' => $percent, '%expected%' => $expected]);

        return sprintf(
            '<svg class="goal-gauge status-%s" viewBox="0 0 120 120" role="img" aria-label="%s">%s</svg>',
            self::escape($progress->status->value),
            self::escape($label),
            implode('', $svg),
        );
    }

    /**
     * Data of the projection chart (goals.js), amounts in cents, one point per month from the first measured month to
     * the target month (or the last measured one, if later); null where a line has no point.
     *
     * @param list<array{month: Month, value: int}> $series value of the perimeter each month, from the start
     *
     * @return array{locale: string, labels: list<string>, months: list<string>, actual: list<?int>, plan: list<?int>, projection: list<?int>, target: list<int>, names: array{actual: string, plan: string, projection: string, target: string}, title: string, description: string}|null
     */
    public function chart(GoalProgress $progress, array $series): ?array
    {
        if ($series === []) {
            return null;
        }
        $locale = $this->requests->getCurrentRequest()?->getLocale() ?? 'fr';
        $goal = $progress->goal;
        $first = $series[0]['month'];
        $last = $series[count($series) - 1]['month'];
        $end = $goal->targetMonth->compare($last) > 0 ? $goal->targetMonth : $last;
        $months = Month::range($first, $end);
        $count = count($months);
        $index = static fn(Month $month): int => max(0, min($count - 1, $first->monthsUntil($month)));

        $actual = array_fill(0, $count, null);
        foreach ($series as $point) {
            $actual[$index($point['month'])] = $point['value'];
        }
        // Straight lines: only their two ends are given, Chart.js joins them (spanGaps).
        $plan = array_fill(0, $count, null);
        $plan[$index($goal->startMonth->compare($first) < 0 ? $first : $goal->startMonth)] = $goal->startCents;
        $plan[$index($goal->targetMonth)] = $goal->targetCents;
        $projectionLine = array_fill(0, $count, null);
        $projection = null;
        if ($progress->pace !== null && $progress->required !== null && !$progress->overdue && $goal->targetMonth->compare($last) > 0) {
            $projection = $progress->value + $progress->pace * $last->monthsUntil($goal->targetMonth);
            $projectionLine[$index($last)] = $progress->value;
            $projectionLine[$index($goal->targetMonth)] = $projection;
        }

        $description = $this->translator->trans('Réalisé : %value% en %month%. Trajectoire prévue : %target% en %target_month%.', [
            '%value%' => Money::formatRounded($progress->value, $locale),
            '%month%' => $last->label($locale),
            '%target%' => Money::formatRounded($goal->targetCents, $locale),
            '%target_month%' => $goal->targetMonth->label($locale),
        ]);
        if ($projection !== null) {
            $description .= ' ' . $this->translator->trans('Au rythme actuel : %amount% en %month%.', [
                '%amount%' => Money::formatRounded($projection, $locale),
                '%month%' => $goal->targetMonth->label($locale),
            ]);
        }

        return [
            'locale' => $locale,
            'labels' => array_map(static fn(Month $month): string => $month->shortLabel($locale), $months),
            'months' => array_map(static fn(Month $month): string => $month->label($locale), $months),
            'actual' => array_values($actual),
            'plan' => array_values($plan),
            'projection' => array_values($projectionLine),
            'target' => array_fill(0, $count, $goal->targetCents),
            'names' => [
                'actual' => $this->translator->trans('Réalisé'),
                'plan' => $this->translator->trans('Trajectoire prévue'),
                'projection' => $this->translator->trans('Au rythme actuel'),
                'target' => $this->translator->trans('Cible'),
            ],
            'title' => $this->translator->trans('Projection de l\'objectif %name%', ['%name%' => $goal->name]),
            'description' => $description,
        ];
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
