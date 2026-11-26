<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Light or dark theme chosen by the user, remembered in a cookie.
 *
 * Without a cookie, the system theme applies (prefers-color-scheme). The server writes the choice into the page's
 * data-theme attribute: the right theme shows from the first rendering, without an inline script. The cookie is not
 * HttpOnly, because app.js writes it when switching without reloading; it only holds a display preference.
 */
final class ThemePreference
{
    public const string COOKIE = 'patrimeter_theme';
    public const string LIGHT = 'light';
    public const string DARK = 'dark';

    private const string LIFETIME = '+1 year';

    /** Chosen theme, or null if the user follows the system theme. */
    public static function current(Request $request): ?string
    {
        $theme = $request->cookies->get(self::COOKIE);

        return $theme === self::LIGHT || $theme === self::DARK ? $theme : null;
    }

    /**
     * Theme asked for: "light" or "dark", or else the opposite of the current choice. Without a remembered choice,
     * the server does not know the system theme and switches to dark, light being the most common (with JavaScript,
     * the switch takes the system into account).
     */
    public static function requested(string $requested, ?string $current): string
    {
        if ($requested === self::LIGHT || $requested === self::DARK) {
            return $requested;
        }

        return $current === self::DARK ? self::LIGHT : self::DARK;
    }

    /**
     * Cookie kept one year by the browser. Its expiry is relative to the real time, by which the browser judges it,
     * not to the application's clock (frozen in the tests, where a past date would expire the cookie at once).
     */
    public static function cookie(string $theme, bool $secure): Cookie
    {
        return Cookie::create(self::COOKIE, $theme)
            ->withExpires(self::LIFETIME)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(false)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }
}
