<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Domain\Level;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\NetWorthSnapshot;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Wealth pyramid in SVG (without a library, without inline style: colours through CSS classes).
 *
 * Fixed geometry in a viewBox; the SVG fits the width of its container. The top is truncated so that the
 * "Exotique" tier stays readable. From bottom to top: Main residence, Emergency savings, Euro funds,
 * Shares | Real estate (side by side), Alternative.
 */
final class PyramidSvg
{
    // viewBox sized for a phone screen (scale ≈ 1 at 360 px wide).
    private const float WIDTH = 360.0;
    private const float HEIGHT = 320.0;
    private const float TOP = 4.0;
    private const float BOTTOM = 316.0;
    private const float HALF_TOP = 62.0;    // truncated top: leaves room for the text of the last tier
    private const float HALF_BASE = 178.0;
    private const float GAP = 4.0;

    /** Vertical bounds of the tiers, from the top to the base. */
    private const array BOUNDS = [4.0, 70.0, 140.0, 198.0, 257.0, 316.0];

    /** Vertical offsets of the three lines of text from the centre of the tier. */
    private const float LABEL_OFFSET = -10.0;
    private const float AMOUNT_OFFSET = 6.0;
    private const float SHARE_OFFSET = 20.0;

    public function __construct(private readonly TranslatorInterface $translator) {}

    /** @param bool $amounts false: shares only, without any amount (report shared without revealing the sums) */
    public function render(NetWorthSnapshot $snapshot, bool $amounts = true): string
    {
        $locale = $this->translator->getLocale();
        $cx = self::WIDTH / 2.0;
        $b = self::BOUNDS;
        $parts = [];

        // Tier 5: Alternative (top triangle).
        $parts[] = $this->tier($snapshot, Level::Exotique, $b[0], $b[1], 'left', 'right', $cx, 0.5, true, $amounts);
        // Tier 4: Shares | Real estate.
        $parts[] = $this->tier($snapshot, Level::Actions, $b[1], $b[2], 'left', 'center', $cx, 0.5, true, $amounts);
        $parts[] = $this->tier($snapshot, Level::Immobilier, $b[1], $b[2], 'center', 'right', $cx, 0.5, true, $amounts);
        // Tiers 3 to 1.
        $parts[] = $this->tier($snapshot, Level::FondsEuros, $b[2], $b[3], 'left', 'right', $cx, 0.5, false, $amounts);
        $parts[] = $this->tier($snapshot, Level::Precaution, $b[3], $b[4], 'left', 'right', $cx, 0.5, false, $amounts);
        $parts[] = $this->tier($snapshot, Level::Residence, $b[4], $b[5], 'left', 'right', $cx, 0.5, false, $amounts);

        $title = $this->translator->trans('Pyramide du patrimoine net, %month%', ['%month%' => $snapshot->month->label($locale)]);
        $desc = [];
        foreach (Level::ordered() as $level) {
            $share = Money::formatPercent($snapshot->share($level), 1, $locale);
            $desc[] = $this->translator->trans($level->label(), [], 'levels') . ' : '
                . ($amounts ? Money::formatRounded($snapshot->level($level)->net(), $locale) . ' (' . $share . ')' : $share);
        }

        return sprintf(
            '<svg class="pyramid" viewBox="0 0 %d %d" role="img" aria-labelledby="pyr-title pyr-desc" xmlns="http://www.w3.org/2000/svg">'
            . '<title id="pyr-title">%s</title><desc id="pyr-desc">%s</desc>%s</svg>',
            self::WIDTH,
            self::HEIGHT,
            self::escape($title),
            self::escape(implode(' ; ', $desc)),
            implode('', $parts),
        );
    }

    /** Half-width of the pyramid at ordinate $y. */
    private function halfWidth(float $y): float
    {
        return self::HALF_TOP + (self::HALF_BASE - self::HALF_TOP) * ($y - self::TOP) / (self::BOTTOM - self::TOP);
    }

    /**
     * A tier (or half-tier): polygon + texts.
     *
     * @param 'left'|'center' $from left edge: left side or central axis
     * @param 'center'|'right' $to right edge: central axis or right side
     */
    private function tier(
        NetWorthSnapshot $snapshot,
        Level $level,
        float $yTop,
        float $yBottom,
        string $from,
        string $to,
        float $cx,
        float $textAnchorRatio,
        bool $compact,
        bool $amounts = true,
    ): string {
        $top = $yTop === self::TOP ? $yTop : $yTop + self::GAP / 2.0;
        $bottom = $yBottom === self::BOTTOM ? $yBottom : $yBottom - self::GAP / 2.0;
        $hwTop = $this->halfWidth($top);
        $hwBottom = $this->halfWidth($bottom);
        $half = self::GAP / 2.0;

        $xLeftTop = $from === 'left' ? $cx - $hwTop : $cx + $half;
        $xLeftBottom = $from === 'left' ? $cx - $hwBottom : $cx + $half;
        $xRightTop = $to === 'right' ? $cx + $hwTop : $cx - $half;
        $xRightBottom = $to === 'right' ? $cx + $hwBottom : $cx - $half;

        $points = sprintf(
            '%.1f,%.1f %.1f,%.1f %.1f,%.1f %.1f,%.1f',
            $xLeftTop,
            $top,
            $xRightTop,
            $top,
            $xRightBottom,
            $bottom,
            $xLeftBottom,
            $bottom,
        );

        // Position of the text: horizontal centre of the tier at the chosen height.
        $ty = $top + ($bottom - $top) * $textAnchorRatio;
        $hw = $this->halfWidth($ty);
        $tx = match (true) {
            $from === 'left' && $to === 'right' => $cx,
            $from === 'left' => $cx - $hw / 2.0 - $half / 2.0,
            default => $cx + $hw / 2.0 + $half / 2.0,
        };

        $total = $snapshot->level($level);
        $net = $total->net();
        $locale = $this->translator->getLocale();
        $label = $this->translator->trans($compact ? $level->shortLabel() : $level->label(), [], 'levels');
        $share = $total->isEmpty() ? '' : Money::formatPercent($snapshot->share($level), 1, $locale);
        // Without amounts: the share takes the place of the amount, nothing else.
        $amount = $total->isEmpty() ? '—' : ($amounts ? Money::formatRounded($net, $locale) : $share);
        if (!$amounts) {
            $share = '';
        }

        $classes = 'pyr-tier pyr-' . $level->value . ($total->isEmpty() ? ' is-empty' : '') . ($net < 0 ? ' is-negative' : '');
        $size = $compact ? 'is-compact' : '';

        $text = sprintf('<text class="pyr-label %s" x="%.1f" y="%.1f">%s</text>', $size, $tx, $ty + self::LABEL_OFFSET, self::escape($label))
            . sprintf('<text class="pyr-amount %s" x="%.1f" y="%.1f">%s</text>', $size, $tx, $ty + self::AMOUNT_OFFSET, self::escape($amount));
        if ($share !== '') {
            $text .= sprintf('<text class="pyr-share %s" x="%.1f" y="%.1f">%s</text>', $size, $tx, $ty + self::SHARE_OFFSET, self::escape($share));
        }

        return sprintf(
            '<g class="%s"><title>%s</title><polygon points="%s"/>%s</g>',
            self::escape($classes),
            self::escape($this->translator->trans($level->label(), [], 'levels') . ' : ' . ($amounts ? Money::format($net, false, $locale) : $amount)),
            $points,
            $text,
        );
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
