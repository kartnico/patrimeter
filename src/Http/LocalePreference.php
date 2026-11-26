<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Interface language chosen by the user (selector of the header), remembered in a cookie.
 *
 * Without a cookie, Symfony keeps the browser's preferred language among the enabled languages
 * (framework.set_locale_from_accept_language), otherwise French. The cookie only holds a display
 * preference.
 */
final class LocalePreference
{
    public const string COOKIE = 'patrimeter_locale';
    public const string FRENCH = 'fr';
    public const string ENGLISH = 'en';
    public const array LOCALES = [self::FRENCH, self::ENGLISH];

    private const string LIFETIME = '+1 year';

    /** Chosen language, or null if the user follows their browser's. */
    public static function chosen(Request $request): ?string
    {
        $locale = $request->cookies->get(self::COOKIE);

        return in_array($locale, self::LOCALES, true) ? $locale : null;
    }

    /** Language asked for if it is offered, otherwise the language other than the one shown. */
    public static function requested(string $requested, string $current): string
    {
        if (in_array($requested, self::LOCALES, true)) {
            return $requested;
        }

        return $current === self::ENGLISH ? self::FRENCH : self::ENGLISH;
    }

    /**
     * Cookie kept one year by the browser. Its expiry is relative to the real time, by which the browser judges it,
     * not to the application's clock (frozen in the tests, where a past date would expire the cookie at once).
     */
    public static function cookie(string $locale, bool $secure): Cookie
    {
        return Cookie::create(self::COOKIE, $locale)
            ->withExpires(self::LIFETIME)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
