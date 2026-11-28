<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Look of the interface chosen in My profile for this browser: accent colour and display density, each remembered in a
 * cookie, like the theme (ThemePreference). The server writes the choices that differ from the default look into
 * data-* attributes of the page's <html>, read by app.css: the right look shows from the first rendering, without a
 * script. The cookies only hold display preferences.
 */
final class Appearance
{
    public const string ACCENT_COOKIE = 'patrimeter_accent';
    public const string DENSITY_COOKIE = 'patrimeter_density';

    private const string LIFETIME = '+1 year';

    public function __construct(
        public readonly Accent $accent = Accent::DEFAULT,
        public readonly Density $density = Density::DEFAULT,
    ) {}

    /** Look remembered by this browser; a missing or unknown value keeps the default. */
    public static function of(Request $request): self
    {
        return new self(
            Accent::tryFrom($request->cookies->getString(self::ACCENT_COOKIE)) ?? Accent::DEFAULT,
            Density::tryFrom($request->cookies->getString(self::DENSITY_COOKIE)) ?? Density::DEFAULT,
        );
    }

    /** Look asked for by the form; an unknown value keeps the current choice. */
    public function with(string $accent, string $density): self
    {
        return new self(Accent::tryFrom($accent) ?? $this->accent, Density::tryFrom($density) ?? $this->density);
    }

    /** @return array<string, string> data-* attributes of <html> (name without "data-" => value), default look left out */
    public function attributes(): array
    {
        $attributes = [];
        if ($this->accent !== Accent::DEFAULT) {
            $attributes['accent'] = $this->accent->value;
        }
        if ($this->density !== Density::DEFAULT) {
            $attributes['density'] = $this->density->value;
        }

        return $attributes;
    }

    /**
     * Cookies kept one year by the browser. Their expiry is relative to the real time, by which the browser judges
     * them, not to the application's clock (frozen in the tests). Lax: a link followed from an email shows the page in
     * its chosen look too.
     *
     * @return list<Cookie>
     */
    public function cookies(bool $secure): array
    {
        return [
            self::cookie(self::ACCENT_COOKIE, $this->accent->value, $secure),
            self::cookie(self::DENSITY_COOKIE, $this->density->value, $secure),
        ];
    }

    private static function cookie(string $name, string $value, bool $secure): Cookie
    {
        return Cookie::create($name, $value)
            ->withExpires(self::LIFETIME)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
