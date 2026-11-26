<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** Calendar month in the YYYY-MM format (immutable). */
final class Month implements \Stringable
{
    public const int MIN_YEAR = 1900;

    private const array NAMES = [
        1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
        'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
    ];
    private const array SHORT_NAMES = [
        1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin',
        'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.',
    ];
    private const array ENGLISH_NAMES = [
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];
    private const array ENGLISH_SHORT_NAMES = [
        1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
    ];

    private function __construct(public readonly int $year, public readonly int $month) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])\z/', $value, $m) !== 1) {
            throw new \InvalidArgumentException("Mois invalide : $value");
        }
        $year = (int) $m[1];
        if ($year < self::MIN_YEAR || $year > 2999) {
            throw new \InvalidArgumentException("Année hors limites : $value");
        }

        return new self($year, (int) $m[2]);
    }

    public static function tryFromString(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }
        try {
            return self::fromString($value);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /** Month of a YYYY-MM-DD date. */
    public static function fromDate(string $date): self
    {
        return self::fromString(substr($date, 0, 7));
    }

    /** Month of a given instant, in that instant's time zone. */
    public static function fromDateTime(\DateTimeInterface $date): self
    {
        return new self((int) $date->format('Y'), (int) $date->format('n'));
    }

    public function previous(): self
    {
        return $this->month === 1 ? new self($this->year - 1, 12) : new self($this->year, $this->month - 1);
    }

    public function next(): self
    {
        return $this->month === 12 ? new self($this->year + 1, 1) : new self($this->year, $this->month + 1);
    }

    public function addMonths(int $n): self
    {
        $index = $this->year * 12 + ($this->month - 1) + $n;

        return new self(intdiv($index, 12), $index % 12 + 1);
    }

    /** Number of months from this month to $other (negative if $other is earlier). */
    public function monthsUntil(self $other): int
    {
        return ($other->year * 12 + $other->month) - ($this->year * 12 + $this->month);
    }

    public function compare(self $other): int
    {
        return $this->toString() <=> $other->toString();
    }

    public function equals(self $other): bool
    {
        return $this->year === $other->year && $this->month === $other->month;
    }

    public function toString(): string
    {
        return sprintf('%04d-%02d', $this->year, $this->month);
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->toString();
    }

    /** "novembre 2026", "November 2026" in English. */
    public function label(string $locale = 'fr'): string
    {
        return ($locale === 'en' ? self::ENGLISH_NAMES : self::NAMES)[$this->month] . ' ' . $this->year;
    }

    /**
     * Complement "de novembre 2026" / "d’octobre 2026" (elision before a vowel); in English, the month alone
     * ("November 2026"): the translated sentence places the preposition itself.
     */
    public function ofLabel(string $locale = 'fr'): string
    {
        if ($locale === 'en') {
            return $this->label($locale);
        }

        return (in_array($this->month, [4, 8, 10], true) ? 'd’' : 'de ') . $this->label();
    }

    /** "nov. 2026", "Nov 2026" in English. */
    public function shortLabel(string $locale = 'fr'): string
    {
        return ($locale === 'en' ? self::ENGLISH_SHORT_NAMES : self::SHORT_NAMES)[$this->month] . ' ' . $this->year;
    }

    /**
     * Months from $from to $to inclusive.
     *
     * @return list<self>
     */
    public static function range(self $from, self $to): array
    {
        $months = [];
        for ($m = $from; $m->compare($to) <= 0; $m = $m->next()) {
            $months[] = $m;
        }

        return $months;
    }
}
