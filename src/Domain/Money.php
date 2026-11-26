<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Conversion of amounts between user input, cents (integers) and display, in French
 * ("1 234,56 €", by default) or in English ("€1,234.56"). No float is used: everything is done
 * on strings and integers.
 */
final class Money
{
    /** Safety ceiling: 13 digits before the decimal point, i.e. less than 10,000 billion euros. */
    private const int MAX_EURO_DIGITS = 13;

    private const string NNBSP = "\u{202F}"; // narrow no-break space (thousands separator)
    private const string NBSP = "\u{00A0}";  // no-break space (before the € symbol)

    /** Largest amount accepted, in cents: 13 digits of euros. */
    public const int MAX_CENTS = 999_999_999_999_999;

    /**
     * Parses an input ("1 234,56", "1234.5", "-12", "1.234,56 €", "1,234.56"…) in the page's language. A single
     * separator followed by groups of three digits is the language's thousands separator: "1.234" is €1,234 in
     * French, "1,234" is €1,234 in English; the other reading (three decimals) is refused.
     *
     * @throws \InvalidArgumentException if the input is not a valid amount
     */
    public static function parse(string $input, string $locale = 'fr'): int
    {
        $s = str_replace([' ', self::NBSP, self::NNBSP, "\t", '€', "'"], '', trim($input));
        $s = str_replace("\u{2212}", '-', $s); // typographic minus sign

        $negative = false;
        if ($s !== '' && ($s[0] === '-' || $s[0] === '+')) {
            $negative = $s[0] === '-';
            $s = substr($s, 1);
        }
        if ($s === '') {
            throw new \InvalidArgumentException('Montant vide.');
        }

        $hasComma = str_contains($s, ',');
        $hasDot = str_contains($s, '.');
        if ($hasComma && $hasDot && preg_match('/^[1-9]\d{0,2}(,\d{3})+\.\d{0,2}$/D', $s) === 1) {
            // "1,234.56" (English): commas = thousands, dot = decimals.
            $s = str_replace(',', '', $s);
        } elseif ($hasComma && $hasDot) {
            // "1.234,56": dots = thousands, comma = decimals.
            if (preg_match('/^[1-9]\d{0,2}(\.\d{3})+,\d{0,2}$/D', $s) !== 1) {
                throw new \InvalidArgumentException('Montant invalide.');
            }
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($hasComma && $locale === 'en' && preg_match('/^[1-9]\d{0,2}(,\d{3})+$/D', $s) === 1) {
            // "1,234" or "12,345,678" in English: thousands separators.
            $s = str_replace(',', '', $s);
        } elseif ($hasComma) {
            $s = str_replace(',', '.', $s);
        } elseif ($hasDot && $locale !== 'en' && preg_match('/^[1-9]\d{0,2}(\.\d{3})+$/D', $s) === 1) {
            // "1.234" or "12.345.678" in French: thousands separators; "0.500" is not one.
            $s = str_replace('.', '', $s);
        }

        if (preg_match('/^(\d+)(?:\.(\d{0,2}))?$/D', $s, $m) !== 1) {
            throw new \InvalidArgumentException('Montant invalide (2 décimales maximum).');
        }
        $euros = ltrim($m[1], '0');
        if (strlen($euros) > self::MAX_EURO_DIGITS) {
            throw new \InvalidArgumentException('Montant trop grand.');
        }
        $cents = (int) ($euros === '' ? '0' : $euros) * 100 + (int) str_pad($m[2] ?? '', 2, '0');

        return $negative ? -$cents : $cents;
    }

    /** "1 234,56 €" ("€1,234.56" in English); with $signed, a "+" prefix for positive amounts. */
    public static function format(int $cents, bool $signed = false, string $locale = 'fr'): string
    {
        $sign = $cents < 0 ? '−' : ($signed && $cents > 0 ? '+' : '');
        $abs = abs($cents);
        $amount = self::groupThousands((string) intdiv($abs, 100), $locale) . self::decimalSeparator($locale)
            . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        return $sign . self::withSymbol($amount, $locale);
    }

    /** Amount rounded to the euro: "1 235 €", "€1,235" (compact displays). */
    public static function formatRounded(int $cents, string $locale = 'fr'): string
    {
        $abs = abs($cents);
        $euros = intdiv($abs + 50, 100);
        $sign = $cents < 0 && $euros > 0 ? '−' : '';

        return $sign . self::withSymbol(self::groupThousands((string) $euros, $locale), $locale);
    }

    /** Percentage: "12,3 %" in French, "12.3%" in English. */
    public static function formatPercent(?float $value, int $decimals = 1, string $locale = 'fr'): string
    {
        if ($value === null || !is_finite($value)) {
            return '—';
        }
        $english = $locale === 'en';
        $formatted = number_format(abs($value), $decimals, $english ? '.' : ',', $english ? ',' : self::NNBSP);
        // No "−0,0 %": the sign only shows if the rounded value is not zero.
        $sign = $value < 0 && round(abs($value), $decimals) > 0 ? '−' : '';

        return $sign . $formatted . ($english ? '' : self::NBSP) . '%';
    }

    /** Value for a form field: "1234,56" ("1234.56" in English), without thousands separator. */
    public static function toInput(int $cents, string $locale = 'fr'): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '') . intdiv($abs, 100) . self::decimalSeparator($locale) . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function decimalSeparator(string $locale): string
    {
        return $locale === 'en' ? '.' : ',';
    }

    private static function withSymbol(string $amount, string $locale): string
    {
        return $locale === 'en' ? '€' . $amount : $amount . self::NBSP . '€';
    }

    /** "1234567" → "1 234 567" (narrow no-break spaces), "1,234,567" in English. */
    private static function groupThousands(string $digits, string $locale = 'fr'): string
    {
        return preg_replace('/\B(?=(\d{3})+$)/', $locale === 'en' ? ',' : self::NNBSP, $digits) ?? $digits;
    }
}
