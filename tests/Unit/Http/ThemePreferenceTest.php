<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Http;

use Patrimeter\Http\ThemePreference;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ThemePreferenceTest extends TestCase
{
    public function testCurrentThemeComesFromAValidCookieOnly(): void
    {
        self::assertNull(ThemePreference::current(new Request()));
        self::assertSame('dark', ThemePreference::current(new Request(cookies: [ThemePreference::COOKIE => 'dark'])));
        self::assertSame('light', ThemePreference::current(new Request(cookies: [ThemePreference::COOKIE => 'light'])));
        self::assertNull(ThemePreference::current(new Request(cookies: [ThemePreference::COOKIE => 'blue'])));
    }

    public function testRequestedTheme(): void
    {
        self::assertSame('light', ThemePreference::requested('light', 'light'));
        self::assertSame('dark', ThemePreference::requested('dark', null));
        self::assertSame('light', ThemePreference::requested('toggle', 'dark'));
        self::assertSame('dark', ThemePreference::requested('toggle', 'light'));
        self::assertSame('dark', ThemePreference::requested('toggle', null));
        self::assertSame('dark', ThemePreference::requested('n\'importe quoi', null));
    }

    public function testCookieIsSecureOnlyOverHttps(): void
    {
        self::assertTrue(ThemePreference::cookie('dark', true)->isSecure());
        self::assertFalse(ThemePreference::cookie('dark', false)->isSecure());
        // One year from now, in real time: the browser judges the expiry with its own clock.
        self::assertEqualsWithDelta(strtotime('+1 year'), ThemePreference::cookie('dark', false)->getExpiresTime(), 5);
    }
}
