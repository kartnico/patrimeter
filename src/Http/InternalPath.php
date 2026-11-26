<?php

declare(strict_types=1);

namespace Patrimeter\Http;

/**
 * Return address given by a form: only internal paths are accepted, so that a trapped link cannot redirect
 * to another site (open redirect).
 */
final class InternalPath
{
    private const string FALLBACK = '/';

    /**
     * The path if it is internal ("/historique?vue=niveaux"), otherwise "/": the result can only point to this site,
     * never to another scheme or host, so it is safe as a redirect target.
     *
     * @psalm-taint-escape redirect
     * @psalm-taint-escape url_scheme
     * @psalm-taint-escape url_path
     * @psalm-taint-escape url_component
     */
    public static function sanitize(?string $path): string
    {
        if ($path === null
            || !str_starts_with($path, '/')
            || str_starts_with($path, '//')          // "//site.example" points to another host
            || str_contains($path, '\\')             // "/\site.example" too, for some browsers
            || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return self::FALLBACK;
        }

        return $path;
    }

    /** Path (and parameters) of an absolute address remembered by the firewall; "/" failing that. */
    public static function fromUrl(?string $url): string
    {
        if ($url === null) {
            return self::FALLBACK;
        }
        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return self::sanitize((is_string($path) ? $path : self::FALLBACK) . (is_string($query) ? '?' . $query : ''));
    }
}
