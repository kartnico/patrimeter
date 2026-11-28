<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\AccountActivity;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\Nature;
use PHPUnit\Framework\TestCase;

/** Span of the active accounts kept for the monthly reminder, sent without the key that opens the accounts. */
final class AccountActivityTest extends TestCase
{
    public function testWithoutAccountNothingIsActive(): void
    {
        $activity = AccountActivity::of([]);

        self::assertNull($activity->from);
        self::assertFalse($activity->covers(Month::fromString('2026-11')));
    }

    public function testFromTheFirstOpeningAndOpenEndedWhileAnAccountStaysOpen(): void
    {
        $activity = AccountActivity::of([self::account('2025-03-10', '2026-02-15'), self::account('2024-06-01', null)]);

        self::assertSame('2024-06', $activity->from?->toString());
        self::assertNull($activity->until);
        self::assertFalse($activity->covers(Month::fromString('2024-05')), 'before the first opening');
        self::assertTrue($activity->covers(Month::fromString('2024-06')), 'opened in the month: to enter');
        self::assertTrue($activity->covers(Month::fromString('2031-01')));
    }

    public function testAllClosedEndsWithTheLastClosingMonth(): void
    {
        $activity = AccountActivity::of([self::account('2020-01-01', '2026-10-15'), self::account('2021-05-01', '2025-01-31')]);

        self::assertSame('2026-10', $activity->until?->toString());
        self::assertTrue($activity->covers(Month::fromString('2026-09')));
        self::assertFalse($activity->covers(Month::fromString('2026-10')), 'closed in the month: not entered, as in the form');
        self::assertFalse($activity->covers(Month::fromString('2026-11')));
    }

    private static function account(string $openedOn, ?string $closedOn): Account
    {
        return new Account(1, 'Livret', 1, 'livret_a', Level::Precaution, Nature::Asset, $openedOn, $closedOn);
    }
}
