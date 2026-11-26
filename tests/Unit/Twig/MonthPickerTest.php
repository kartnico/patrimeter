<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Twig;

use Patrimeter\Domain\Month;
use Patrimeter\Twig\MonthPicker;
use PHPUnit\Framework\TestCase;

final class MonthPickerTest extends TestCase
{
    public function testMonthsOfTheCurrentYear(): void
    {
        $picker = new MonthPicker(Month::fromString('2026-05'), Month::fromString('2026-11'), Month::fromString('2026-03'), '/');

        self::assertSame(2026, $picker->year);
        self::assertCount(12, $picker->months);
        self::assertSame(['label' => 'Janv.', 'aria_label' => 'Janvier 2026', 'href' => '/?mois=2026-01', 'classes' => 'mp-cell is-empty', 'current' => false], $picker->months[0]);
        self::assertSame(['label' => 'Mai', 'aria_label' => 'Mai 2026', 'href' => '/?mois=2026-05', 'classes' => 'mp-cell', 'current' => true], $picker->months[4]);
        self::assertSame('mp-cell is-today', $picker->months[10]['classes']);
        self::assertSame(['label' => 'Déc.', 'aria_label' => 'Décembre 2026', 'href' => null, 'classes' => 'mp-cell is-disabled', 'current' => false], $picker->months[11]);
    }

    public function testTheEntriesAllowNextMonthWhileTodayStaysMarked(): void
    {
        $picker = new MonthPicker(Month::fromString('2026-11'), Month::fromString('2026-12'), null, '/saisie', 'fr', Month::fromString('2026-11'));

        self::assertSame('2026-11', $picker->todayMonth()->toString());
        self::assertStringContainsString('is-today', $picker->months[10]['classes'], 'November: the current month');
        self::assertStringNotContainsString('is-today', $picker->months[11]['classes']);
        self::assertSame('/saisie?mois=2026-12', $picker->months[11]['href'], 'December can be chosen');
        self::assertSame('2026-12', (new MonthPicker(Month::fromString('2026-12'), Month::fromString('2026-12'), null, '/'))->todayMonth()->toString(), 'without today: the last month');

        $nextYear = new MonthPicker(Month::fromString('2026-12'), Month::fromString('2027-01'), null, '/saisie', 'fr', Month::fromString('2026-12'));
        $years = array_column($nextYear->years, 'today', 'year');
        self::assertSame(2027, array_key_last($years), 'the year of next month can be chosen');
        self::assertTrue($years[2026], '2026 marked, not 2027');
        self::assertFalse($years[2027]);
    }

    public function testYearLinksStayWithinTheSelectableRange(): void
    {
        $picker = new MonthPicker(Month::fromString('2025-12'), Month::fromString('2026-11'), null, '/saisie');

        self::assertSame('/saisie?mois=2024-12', $picker->previousYearHref);
        self::assertSame('/saisie?mois=2026-11', $picker->nextYearHref, 'décembre 2026 est futur : borné à novembre');
        self::assertNull((new MonthPicker(Month::fromString('2026-02'), Month::fromString('2026-11'), null, '/'))->nextYearHref);
    }

    public function testYearGridGoesBackFourYearsBeforeTheOldestKnownMonth(): void
    {
        $picker = new MonthPicker(Month::fromString('2026-11'), Month::fromString('2026-11'), Month::fromString('2023-06'), '/');

        self::assertSame(range(2019, 2026), array_column($picker->years, 'year'));
        self::assertSame(['year' => 2026, 'today' => true, 'current' => true], $picker->years[7]);
        self::assertSame(['year' => 2019, 'today' => false, 'current' => false], $picker->years[0]);
    }

    public function testNoLinkBeforeTheFirstRepresentableYear(): void
    {
        self::assertNull((new MonthPicker(Month::fromString('1900-03'), Month::fromString('2026-11'), null, '/'))->previousYearHref);
        self::assertSame('/?mois=1900-03', (new MonthPicker(Month::fromString('1901-03'), Month::fromString('2026-11'), null, '/'))->previousYearHref);
    }
}
