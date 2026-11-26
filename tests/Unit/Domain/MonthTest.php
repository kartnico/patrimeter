<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Month;
use PHPUnit\Framework\TestCase;

final class MonthTest extends TestCase
{
    public function testNavigation(): void
    {
        $m = Month::fromString('2026-01');
        self::assertSame('2025-12', (string) $m->previous());
        self::assertSame('2026-02', (string) $m->next());
        self::assertSame('2027-03', (string) $m->addMonths(14));
        self::assertSame('2025-11', (string) $m->addMonths(-2));
    }

    public function testRange(): void
    {
        $range = Month::range(Month::fromString('2025-11'), Month::fromString('2026-02'));
        self::assertSame(['2025-11', '2025-12', '2026-01', '2026-02'], array_map('strval', $range));
        self::assertSame([], Month::range(Month::fromString('2026-02'), Month::fromString('2026-01')));
    }

    public function testLabels(): void
    {
        self::assertSame('février 2026', Month::fromString('2026-02')->label());
        self::assertSame('déc. 2026', Month::fromString('2026-12')->shortLabel());
        self::assertSame('d’octobre 2026', Month::fromString('2026-10')->ofLabel());
        self::assertSame('de mai 2026', Month::fromString('2026-05')->ofLabel());
    }

    public function testFromDateTimeUsesTheGivenTimezone(): void
    {
        $date = new \DateTimeImmutable('2026-11-30 23:30:00', new \DateTimeZone('UTC'));

        self::assertSame('2026-11', Month::fromDateTime($date)->toString());
        self::assertSame('2026-12', Month::fromDateTime($date->setTimezone(new \DateTimeZone('Europe/Paris')))->toString());
    }

    public function testYearOutOfRange(): void
    {
        self::assertNull(Month::tryFromString('1899-12'));
        self::assertNull(Month::tryFromString('3000-01'));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Année hors limites');
        Month::fromString('1899-12');
    }

    public function testComparisons(): void
    {
        $january = Month::fromString('2026-01');

        self::assertTrue($january->equals(Month::fromString('2026-01')));
        self::assertFalse($january->equals(Month::fromString('2027-01')));
        self::assertSame(-1, $january->compare(Month::fromString('2026-02')));
        self::assertSame('2026-01', (string) $january);
    }

    public function testInvalid(): void
    {
        self::assertNull(Month::tryFromString('2026-13'));
        self::assertNull(Month::tryFromString('2026-1'));
        self::assertNull(Month::tryFromString("2026-01\n"));
        self::assertNull(Month::tryFromString(null));
        self::assertSame('2026-03', (string) Month::fromDate('2026-03-31'));
    }
}
