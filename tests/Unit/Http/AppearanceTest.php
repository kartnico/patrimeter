<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Http;

use Patrimeter\Http\Accent;
use Patrimeter\Http\Appearance;
use Patrimeter\Http\Density;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

final class AppearanceTest extends TestCase
{
    public function testWithoutCookiesTheDefaultLookApplies(): void
    {
        $appearance = Appearance::of(new Request());

        self::assertSame(Accent::Forest, $appearance->accent);
        self::assertSame(Density::Comfortable, $appearance->density);
        self::assertSame([], $appearance->attributes(), 'the default look needs no attribute');
    }

    public function testTheLookComesFromValidCookiesOnly(): void
    {
        $chosen = Appearance::of(new Request(cookies: [Appearance::ACCENT_COOKIE => 'ocean', Appearance::DENSITY_COOKIE => 'compact']));
        self::assertSame(['accent' => 'ocean', 'density' => 'compact'], $chosen->attributes());

        $unknown = Appearance::of(new Request(cookies: [Appearance::ACCENT_COOKIE => '"><script>', Appearance::DENSITY_COOKIE => 'tiny']));
        self::assertSame([], $unknown->attributes());
    }

    public function testAnUnknownChoiceKeepsTheCurrentOne(): void
    {
        $current = new Appearance(Accent::Slate, Density::Compact);

        self::assertEquals(new Appearance(Accent::Aurora, Density::Compact), $current->with('aurora', ''));
        self::assertEquals(new Appearance(Accent::Slate, Density::Comfortable), $current->with('purple', 'comfortable'));
    }

    public function testCookiesHoldEachChoiceForAYear(): void
    {
        $cookies = (new Appearance(Accent::Aurora, Density::Compact))->cookies(false);

        self::assertSame([Appearance::ACCENT_COOKIE => 'aurora', Appearance::DENSITY_COOKIE => 'compact'], array_combine(
            array_map(static fn(Cookie $cookie): string => $cookie->getName(), $cookies),
            array_map(static fn(Cookie $cookie): ?string => $cookie->getValue(), $cookies),
        ));
        foreach ($cookies as $cookie) {
            self::assertTrue($cookie->isHttpOnly(), 'no script reads them');
            self::assertSame(Cookie::SAMESITE_LAX, $cookie->getSameSite());
            self::assertSame('/', $cookie->getPath());
            self::assertFalse($cookie->isSecure());
            // One year from now, in real time: the browser judges the expiry with its own clock.
            self::assertEqualsWithDelta(strtotime('+1 year'), $cookie->getExpiresTime(), 5);
        }
        self::assertTrue((new Appearance())->cookies(true)[0]->isSecure(), 'secure over HTTPS');
    }
}
