<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Month;
use Patrimeter\Domain\Valuation;
use PHPUnit\Framework\TestCase;

/** Automatic carry-over of the last known balance. */
final class ValuationTest extends TestCase
{
    private Valuation $valuation;

    #[\Override]
    protected function setUp(): void
    {
        $this->valuation = new Valuation([
            1 => ['2026-03' => 300, '2026-01' => 100, '2026-06' => 600],
            2 => ['2026-05' => 5000],
        ]);
    }

    public function testEnteredValue(): void
    {
        $v = $this->valuation->valueAt(1, Month::fromString('2026-03'));
        self::assertNotNull($v);
        self::assertSame(300, $v->cents);
        self::assertFalse($v->carried);
        self::assertSame('2026-03', (string) $v->sourceMonth);
    }

    public function testCarriesLastKnownValue(): void
    {
        $v = $this->valuation->valueAt(1, Month::fromString('2026-05'));
        self::assertNotNull($v);
        self::assertSame(300, $v->cents);
        self::assertTrue($v->carried);
        self::assertSame('2026-03', (string) $v->sourceMonth);

        $v = $this->valuation->valueAt(1, Month::fromString('2027-12'));
        self::assertSame(600, $v?->cents);
        self::assertTrue($v->carried);
    }

    public function testCarriesAcrossYears(): void
    {
        $v = (new Valuation([7 => ['2024-11' => 42]]))->valueAt(7, Month::fromString('2026-02'));
        self::assertSame(42, $v?->cents);
        self::assertSame('2024-11', (string) $v->sourceMonth);
    }

    public function testNoValueBeforeFirstStatement(): void
    {
        self::assertNull($this->valuation->valueAt(1, Month::fromString('2025-12')));
        self::assertNull($this->valuation->valueAt(2, Month::fromString('2026-04')));
        self::assertNull($this->valuation->valueAt(99, Month::fromString('2026-04')));
    }

    public function testFirstMonth(): void
    {
        self::assertSame('2026-01', (string) $this->valuation->firstMonth());
        self::assertNull((new Valuation([]))->firstMonth());
    }

    public function testManyStatements(): void
    {
        $statements = [];
        for ($m = Month::fromString('2000-01'), $i = 0; $i < 300; $m = $m->addMonths(2), $i++) {
            $statements[(string) $m] = $i;
        }
        $valuation = new Valuation([1 => $statements]);
        // 2000-01 + 2*150 months = 2025-01 (i=150); 2025-02 must carry over 150.
        self::assertSame(150, $valuation->valueAt(1, Month::fromString('2025-02'))?->cents);
        self::assertSame(150, $valuation->valueAt(1, Month::fromString('2025-01'))?->cents);
        self::assertFalse($valuation->valueAt(1, Month::fromString('2025-01'))->carried);
    }
}
