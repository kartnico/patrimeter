<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

use Patrimeter\Http\PublicUrl;

/**
 * Protected resources (RFC 8707, RFC 9728): a token is only valid for the one it is meant for. A
 * personal token, created by the user themselves, is valid for both.
 */
final class ApiResource
{
    /** Personal token: valid for the API and for MCP. */
    public const string ANY = '*';

    public function __construct(private readonly PublicUrl $urls) {}

    public function issuer(): string
    {
        return $this->urls->base();
    }

    public function mcp(): string
    {
        return $this->urls->base() . '/mcp';
    }

    public function api(): string
    {
        return $this->urls->base() . '/api';
    }

    public function forPath(string $path): string
    {
        return str_starts_with($path, '/mcp') ? $this->mcp() : $this->api();
    }

    /** Requested resource, normalised; null if this server does not know it. Missing: the API. */
    public function normalize(string $requested): ?string
    {
        if ($requested === '') {
            return $this->api();
        }
        $candidate = rtrim($requested, '/');

        return in_array($candidate, [$this->mcp(), $this->api()], true) ? $candidate : null;
    }

    public function metadataUrl(string $resource): string
    {
        return $this->urls->base() . '/.well-known/oauth-protected-resource' . ($resource === $this->mcp() ? '/mcp' : '/api');
    }
}
