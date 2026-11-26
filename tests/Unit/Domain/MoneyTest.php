<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function validInputs(): iterable
    {
        yield 'entier' => ['1234', 123400];
        yield 'virgule' => ['1234,56', 123456];
        yield 'point décimal' => ['1234.5', 123450];
        yield 'espaces' => ['1 234 567,89', 123456789];
        yield 'espace insécable' => ["12\u{00A0}000,10", 1200010];
        yield 'espace fine' => ["12\u{202F}000", 1200000];
        yield 'symbole euro' => ['250 €', 25000];
        yield 'milliers à points' => ['1.234.567,8', 123456780];
        yield 'milliers à point sans décimales' => ['12.345', 1234500];
        yield 'négatif' => ['-45,3', -4530];
        yield 'moins typographique' => ["\u{2212}12", -1200];
        yield 'zéro' => ['0', 0];
        yield 'virgule finale' => ['10,', 1000];
        yield 'zéros en tête' => ['007,05', 705];
        yield 'format anglais (virgules de milliers, point décimal)' => ['1,234,567.89', 123456789];
    }

    #[DataProvider('validInputs')]
    public function testParse(string $input, int $expected): void
    {
        self::assertSame($expected, Money::parse($input));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidInputs(): iterable
    {
        yield 'point suivi de trois chiffres, sans milliers' => ['0.500'];
        yield 'zéro en tête de milliers' => ['0.123'];
        yield 'zéro en tête de milliers avec virgule' => ['0.123,45'];
        yield 'vide' => [''];
        yield 'lettres' => ['abc'];
        yield 'trois décimales' => ['1,234'];
        yield 'deux virgules' => ['1,2,3'];
        yield 'mélange incohérent' => ['1,23.456'];
        yield 'point de milliers puis point décimal' => ['1.234.56'];
        yield 'zéro en tête de milliers à virgules' => ['01,234.56'];
        yield 'zéro en tête de milliers à points' => ['01.234'];
        yield 'signe seul' => ['-'];
        yield 'trop grand' => ['99999999999999'];
        yield 'exposant' => ['1e5'];
    }

    #[DataProvider('invalidInputs')]
    public function testParseRejectsInvalidInput(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::parse($input);
    }

    /** @return iterable<string, array{string, int}> */
    public static function englishInputs(): iterable
    {
        yield 'virgule de milliers sans décimales' => ['1,234', 123400];
        yield 'plusieurs virgules de milliers' => ['12,345,678', 1234567800];
        yield 'virgules de milliers et point décimal' => ['1,234.56', 123456];
        yield 'point décimal' => ['1234.5', 123450];
        yield 'symbole euro en tête' => ['€1,000', 100000];
        yield 'négatif' => ["\u{2212}1,500", -150000];
        yield 'virgule décimale tolérée' => ['12,5', 1250];
    }

    #[DataProvider('englishInputs')]
    public function testParseInEnglish(string $input, int $expected): void
    {
        self::assertSame($expected, Money::parse($input, 'en'));
    }

    /** Bug: on the English page, "1,000" (a thousand, English style) was refused as an amount with three decimals. */
    public function testTheThousandsSeparatorFollowsTheLanguage(): void
    {
        self::assertSame(100000, Money::parse('1,000', 'en'));
        self::assertSame(100000, Money::parse('1.000', 'fr'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function otherLanguageThousands(): iterable
    {
        yield 'virgule suivie de trois chiffres, en français' => ['1,000', 'fr'];
        yield 'point suivi de trois chiffres, en anglais' => ['1.000', 'en'];
        yield 'points de milliers, en anglais' => ['12.345.678', 'en'];
        yield 'groupe de quatre chiffres après la virgule, en anglais' => ['1,2345', 'en'];
        yield 'zéro en tête de milliers, en anglais' => ['01,234', 'en'];
    }

    /** The other reading of the same text, three decimals, stays refused: never an amount a thousand times too small. */
    #[DataProvider('otherLanguageThousands')]
    public function testTheOtherReadingIsRejected(string $input, string $locale): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::parse($input, $locale);
    }

    public function testFormat(): void
    {
        self::assertSame("1\u{202F}234,56\u{00A0}€", Money::format(123456));
        self::assertSame("0,05\u{00A0}€", Money::format(5));
        self::assertSame("−1\u{202F}000\u{202F}000,00\u{00A0}€", Money::format(-100000000));
        self::assertSame("+12,00\u{00A0}€", Money::format(1200, true));
        self::assertSame("0,00\u{00A0}€", Money::format(0, true));
    }

    public function testEnglishFormats(): void
    {
        self::assertSame('€1,234.56', Money::format(123456, false, 'en'));
        self::assertSame('−€1,000,000.00', Money::format(-100000000, false, 'en'));
        self::assertSame('+€12.00', Money::format(1200, true, 'en'));
        self::assertSame('€1,235', Money::formatRounded(123456, 'en'));
        self::assertSame('12.3%', Money::formatPercent(12.34, 1, 'en'));
        self::assertSame('−4.5%', Money::formatPercent(-4.5, 1, 'en'));
        self::assertSame('1234.56', Money::toInput(123456, 'en'));
        self::assertSame(123456, Money::parse(Money::toInput(123456, 'en')));
    }

    public function testFormatRoundedAndPercent(): void
    {
        self::assertSame("1\u{202F}235\u{00A0}€", Money::formatRounded(123450));
        self::assertSame("1\u{202F}234\u{00A0}€", Money::formatRounded(123449));
        self::assertSame("−10\u{00A0}€", Money::formatRounded(-1000));
        self::assertSame("0\u{00A0}€", Money::formatRounded(-20));
        self::assertSame("12,3\u{00A0}%", Money::formatPercent(12.345));
        self::assertSame("−4,0\u{00A0}%", Money::formatPercent(-4.0));
        self::assertSame("0,0\u{00A0}%", Money::formatPercent(-0.01));
        self::assertSame('—', Money::formatPercent(null));
        self::assertSame('—', Money::formatPercent(INF));
        self::assertSame('—', Money::formatPercent(NAN));
    }

    public function testLargestAcceptedAmount(): void
    {
        self::assertSame(999_999_999_999_999, Money::parse('9 999 999 999 999,99'));
        $this->expectExceptionMessage('Montant trop grand.');
        Money::parse('10 000 000 000 000');
    }

    public function testToInputRoundTrip(): void
    {
        foreach ([0, 5, 123456, -4530, 100000000] as $cents) {
            self::assertSame($cents, Money::parse(Money::toInput($cents)));
        }
        self::assertSame('-45,30', Money::toInput(-4530));
    }
}
