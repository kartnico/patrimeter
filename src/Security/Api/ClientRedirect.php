<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

/**
 * Redirect addresses of the OAuth clients: HTTPS, or HTTP on the loopback for an installed application.
 * Exact comparison, except for the loopback port, which the application chooses at each launch
 * (RFC 8252).
 */
final class ClientRedirect
{
    private const array LOOPBACK = ['127.0.0.1', '[::1]', 'localhost'];

    public static function isAcceptable(string $uri): bool
    {
        $parts = parse_url($uri);
        if (preg_match('/[\s\x00-\x1F]/', $uri) === 1 || $parts === false || !isset($parts['scheme'], $parts['host']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        return $parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && in_array($parts['host'], self::LOOPBACK, true));
    }

    /** @param list<string> $registered */
    public static function isRegistered(string $uri, array $registered): bool
    {
        if (in_array($uri, $registered, true)) {
            return true;
        }
        $withoutPort = self::loopbackWithoutPort($uri);
        if ($withoutPort === null) {
            return false;
        }
        foreach ($registered as $candidate) {
            if (self::loopbackWithoutPort($candidate) === $withoutPort) {
                return true;
            }
        }

        return false;
    }

    private static function loopbackWithoutPort(string $uri): ?string
    {
        $parts = parse_url($uri);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'http' || !isset($parts['host']) || !in_array($parts['host'], self::LOOPBACK, true) || isset($parts['fragment'])) {
            return null;
        }

        return $parts['host'] . ($parts['path'] ?? '') . '?' . ($parts['query'] ?? '');
    }
}
