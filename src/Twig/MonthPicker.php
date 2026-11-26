<?php

declare(strict_types=1);

namespace Patrimeter\Twig;

use Patrimeter\Domain\Month;

/**
 * Data of the month picker (year + grid of 12 months), calculated here so that the template stays free of logic.
 * The rendering without JavaScript relies on these links; app.js adds the change of year without reloading, the
 * grid of years and the keyboard.
 */
final class MonthPicker
{
    private const int MONTHS_PER_YEAR = 12;
    /** Years offered before the earliest known (data or month shown). */
    private const int EXTRA_PAST_YEARS = 4;
    private const int MIN_YEAR = 1900;

    /** @var list<array{label: string, aria_label: string, href: ?string, classes: string, current: bool}> */
    public readonly array $months;

    /** @var list<array{year: int, today: bool, current: bool}> */
    public readonly array $years;

    /** @var list<string> short names of the twelve months, in the interface language (for app.js) */
    public readonly array $shortNames;

    /** @var list<string> long names of the twelve months */
    public readonly array $longNames;

    public readonly int $year;
    public readonly ?string $previousYearHref;
    public readonly ?string $nextYearHref;

    /**
     * @param Month      $selected month shown
     * @param Month      $max      last selectable month (current month, or the next one once entered or for entries)
     * @param Month|null $first    first month with data (null if unknown)
     * @param string     $base     target path, completed by ?mois=YYYY-MM
     * @param string     $locale   language of the month names ("fr" or "en")
     * @param Month|null $today    current month, marked in the calendar (default: $max)
     */
    public function __construct(
        public readonly Month $selected,
        public readonly Month $max,
        public readonly ?Month $first,
        public readonly string $base,
        public readonly string $locale = 'fr',
        private readonly ?Month $today = null,
    ) {
        $this->year = $selected->year;
        $this->previousYearHref = $this->year > Month::MIN_YEAR ? $this->yearHref($this->year - 1) : null;
        $this->nextYearHref = $this->year < $max->year ? $this->yearHref($this->year + 1) : null;
        $this->months = $this->monthCells();
        $this->years = $this->yearCells();
        $shortNames = [];
        $longNames = [];
        for ($m = 1; $m <= self::MONTHS_PER_YEAR; ++$m) {
            $month = Month::fromString(sprintf('2000-%02d', $m));
            $shortNames[] = self::capitalized(explode(' ', $month->shortLabel($locale))[0]);
            $longNames[] = self::capitalized(explode(' ', $month->label($locale))[0]);
        }
        $this->shortNames = $shortNames;
        $this->longNames = $longNames;
    }

    /** Current month, marked in the calendar. */
    public function todayMonth(): Month
    {
        return $this->today ?? $this->max;
    }

    public function href(Month $month): string
    {
        return $this->base . '?mois=' . $month->toString();
    }

    /** Year link without JavaScript: same month in the other year, bounded by the maximum month. */
    private function yearHref(int $year): string
    {
        $month = $year === $this->max->year ? min($this->selected->month, $this->max->month) : $this->selected->month;

        return $this->href(Month::fromString(sprintf('%04d-%02d', $year, $month)));
    }

    /** @return list<array{label: string, aria_label: string, href: ?string, classes: string, current: bool}> */
    private function monthCells(): array
    {
        $cells = [];
        for ($m = 1; $m <= self::MONTHS_PER_YEAR; ++$m) {
            $cell = Month::fromString(sprintf('%04d-%02d', $this->year, $m));
            $future = $cell->compare($this->max) > 0;
            $classes = 'mp-cell'
                . ($cell->equals($this->today ?? $this->max) ? ' is-today' : '')
                . ($this->first !== null && $cell->compare($this->first) < 0 ? ' is-empty' : '')
                . ($future ? ' is-disabled' : '');
            $cells[] = [
                'label' => self::capitalized(explode(' ', $cell->shortLabel($this->locale))[0]),
                'aria_label' => self::capitalized($cell->label($this->locale)),
                'href' => $future ? null : $this->href($cell),
                'classes' => $classes,
                'current' => $cell->equals($this->selected),
            ];
        }

        return $cells;
    }

    /** @return list<array{year: int, today: bool, current: bool}> from the oldest to the most recent */
    private function yearCells(): array
    {
        $oldest = max(self::MIN_YEAR, min($this->first->year ?? $this->max->year, $this->year) - self::EXTRA_PAST_YEARS);
        $cells = [];
        foreach (range($oldest, $this->max->year) as $year) {
            $cells[] = ['year' => $year, 'today' => $year === ($this->today ?? $this->max)->year, 'current' => $year === $this->year];
        }

        return $cells;
    }

    /**
     * Month name with a capital, as in English: a month shown on its own ("Nov.", "Novembre 2026") is written like
     * "Nov." and "November 2026"; within a sentence, French keeps the lower case.
     */
    public static function capitalized(string $label): string
    {
        return mb_strtoupper(mb_substr($label, 0, 1)) . mb_substr($label, 1);
    }
}
