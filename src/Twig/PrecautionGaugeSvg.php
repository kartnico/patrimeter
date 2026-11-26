<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Domain\Budget\PrecautionCoverage;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Gauge of the emergency savings in SVG (without inline style: colours through CSS classes), in months of spending:
 * - track split into zones: less than one month, below the target, from the target to twice it (beyond: surplus);
 * - bar up to the number of months covered, in the colour of the status, with a marker and its value above;
 * - ticks for the target and the end of the scale (twice the target; without a target, from 6 to 24 months).
 * The page's text says the same thing: colour never carries the information alone.
 */
final class PrecautionGaugeSvg
{
    private const float LEFT = 10.0;
    private const float RIGHT = 310.0;
    private const float TRACK_Y = 30.0;
    private const float TRACK_HEIGHT = 14.0;
    private const float LABEL_Y = 62.0;
    private const int MIN_SCALE = 6;
    private const int MAX_SCALE = 24;
    /** Margin below which a label close to an edge aligns with it rather than being centred. */
    private const float EDGE = 40.0;

    public function __construct(private readonly TranslatorInterface $translator) {}

    /** SVG of the gauge; empty string if the month's spending is unknown. */
    public function render(PrecautionCoverage $coverage): string
    {
        $months = $coverage->months();
        if ($months === null) {
            return '';
        }
        $status = $coverage->status();
        $target = $coverage->hasTarget() ? $coverage->targetMonths : 0;
        $scale = $target > 0
            ? max(2, PrecautionCoverage::SURPLUS_FACTOR * $target)
            : max(self::MIN_SCALE, min(self::MAX_SCALE, (int) ceil($months)));

        $zones = [['is-critical', 0, min(1, $scale)]];
        if ($target > 1) {
            $zones[] = ['is-low', 1, $target];
        }
        $zones[] = $target > 0 ? ['is-reached', max(1, $target), $scale] : ['is-neutral', 1, $scale];

        $svg = [];
        foreach ($zones as [$class, $from, $to]) {
            if ($to > $from) {
                $svg[] = sprintf(
                    '<rect class="gauge-zone %s" x="%.1f" y="%.1f" width="%.1f" height="%.1f"/>',
                    $class,
                    $this->x((float) $from, $scale),
                    self::TRACK_Y,
                    $this->x((float) $to, $scale) - $this->x((float) $from, $scale),
                    self::TRACK_HEIGHT,
                );
            }
        }
        $value = $this->x(max(0.0, $months), $scale);
        $svg[] = sprintf(
            '<rect class="gauge-fill is-%s" x="%.1f" y="%.1f" width="%.1f" height="%.1f"/>',
            $status->value,
            self::LEFT,
            self::TRACK_Y + 3.0,
            $value - self::LEFT,
            self::TRACK_HEIGHT - 6.0,
        );
        $svg[] = sprintf('<rect class="gauge-outline" x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="3"/>', self::LEFT, self::TRACK_Y, self::RIGHT - self::LEFT, self::TRACK_HEIGHT);

        // Current value above the track, with a marker pointing at the bar.
        $svg[] = sprintf('<path class="gauge-marker" d="M%.1f %.1fL%.1f %.1fL%.1f %.1fZ"/>', $value - 5.0, self::TRACK_Y - 9.0, $value + 5.0, self::TRACK_Y - 9.0, $value, self::TRACK_Y - 2.0);
        $svg[] = $this->text($value, self::TRACK_Y - 13.0, 'gauge-value', $this->months($months, true));

        // Ticks: origin, target, end of the scale.
        $svg[] = $this->text(self::LEFT, self::LABEL_Y, 'gauge-label', '0');
        if ($target > 0) {
            $x = $this->x((float) $target, $scale);
            $svg[] = sprintf('<line class="gauge-target" x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f"/>', $x, self::TRACK_Y - 3.0, $x, self::TRACK_Y + self::TRACK_HEIGHT + 4.0);
            $svg[] = $this->text($x, self::LABEL_Y, 'gauge-label', $this->translator->trans('cible : %months%', ['%months%' => $this->months((float) $target, false)]));
        }
        $svg[] = $this->text(self::RIGHT, self::LABEL_Y, 'gauge-label', $this->months((float) $scale, false) . ($months > $scale ? ' +' : ''));

        return sprintf(
            '<svg class="precaution-gauge is-%s" viewBox="0 0 320 70" role="img" aria-label="%s">%s</svg>',
            $status->value,
            self::escape($this->summary($months, $target)),
            implode('', $svg),
        );
    }

    private function x(float $months, int $scale): float
    {
        return self::LEFT + (self::RIGHT - self::LEFT) * min(1.0, $months / (float) $scale);
    }

    /** Text centred on $x, aligned with the nearest edge if too close to it. */
    private function text(float $x, float $y, string $class, string $text): string
    {
        $anchor = match (true) {
            $x - self::LEFT < self::EDGE => 'start',
            self::RIGHT - $x < self::EDGE => 'end',
            default => 'middle',
        };

        return sprintf('<text class="%s" x="%.1f" y="%.1f" text-anchor="%s">%s</text>', $class, $x, $y, $anchor, self::escape($text));
    }

    /** "2,8 mois", "3 mois" ("2.8 months", "1 month"); one decimal at most, none for a whole number. */
    public function months(float $months, bool $decimal = true): string
    {
        $rounded = round($months, $decimal ? 1 : 0);
        $english = $this->translator->getLocale() === 'en';
        $formatted = number_format($rounded, floor($rounded) === $rounded ? 0 : 1, $english ? '.' : ',', $english ? ',' : "\u{202F}");

        return $this->translator->trans('%months% mois|%months% mois', ['%count%' => $rounded, '%months%' => $formatted]);
    }

    /** Summary for screen readers. */
    private function summary(float $months, int $target): string
    {
        $covered = $this->translator->trans('Épargne de précaution : %months% de dépenses.', ['%months%' => $this->months($months, true)]);

        return $target > 0
            ? $covered . ' ' . $this->translator->trans('Cible : %months%.', ['%months%' => $this->months((float) $target, false)])
            : $covered;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
