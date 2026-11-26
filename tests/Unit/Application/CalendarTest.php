<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Application;

use Patrimeter\Application\Calendar;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CalendarTest extends TestCase
{
    public function testUsesTheApplicationTimezone(): void
    {
        // 30 November 23:30 UTC: already 1 December in Paris.
        $calendar = new Calendar(new MockClock('2026-11-30 23:30:00', 'UTC'), 'Europe/Paris');

        self::assertSame('2026-12-01', $calendar->today());
        self::assertSame('2026-12', $calendar->currentMonth()->toString());
        self::assertSame('Europe/Paris', $calendar->now()->getTimezone()->getName());
    }

    public function testRejectsAnEmptyTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Calendar(new MockClock(), '');
    }
}
