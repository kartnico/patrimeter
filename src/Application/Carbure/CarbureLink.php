<?php

declare(strict_types=1);

namespace Patrimeter\Application\Carbure;

/**
 * Link of a user with their Carbure instance: address of the instance, API token ("cbt_…", created in Carbure),
 * Carbure spending categories (top level, read at the last connection) and their mapping to the categories of
 * Patrimeter's budget (0: category ignored).
 */
final class CarbureLink
{
    /** Carbure API token: "cbt_" prefix then alphanumeric characters. */
    public const string TOKEN_PATTERN = '/\Acbt_[A-Za-z0-9]{16,128}\z/';

    /**
     * @param array<int, string> $categories Carbure spending category => name
     * @param array<int, int>    $mapping    Carbure category => budget category (0: ignored)
     */
    public function __construct(
        public readonly string $url,
        #[\SensitiveParameter]
        public readonly string $token,
        public readonly array $categories = [],
        public readonly array $mapping = [],
    ) {}

    /**
     * Address of the instance brought back to its base form ("https://carbure.maison.lan", possibly with a path):
     * http or https, a host, no credentials, no parameters, no fragment; null if unsuitable. The address of its MCP
     * service (…/api/mcp) is accepted too, and brought back to that of the instance.
     */
    public static function normalizeUrl(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        // The address of Carbure's MCP service (<instance>/api/mcp, the one Carbure shows) leads to the same instance:
        // the client adds this path itself.
        $url = (string) preg_replace('#/api(?:/mcp)?$#i', '', $url);
        $parts = parse_url($url);
        if ($parts === false || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || strlen($url) > 255
            || preg_match('/[\s\x00-\x1F\x7F]/', $url) === 1) {
            return null;
        }

        return $url;
    }

    public static function isValidToken(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    /** Host of the instance, lowercase. */
    public function host(): string
    {
        return strtolower((string) parse_url($this->url, PHP_URL_HOST));
    }

    /** End of the token, to recognise it without showing it. */
    public function tokenHint(): string
    {
        return 'cbt_…' . substr($this->token, -4);
    }

    /**
     * @param array<int, string> $categories
     * @param array<int, int>    $mapping
     */
    public function with(array $categories, array $mapping): self
    {
        return new self($this->url, $this->token, $categories, $mapping);
    }
}
