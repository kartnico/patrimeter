<?php

declare(strict_types=1);

namespace Patrimeter\Http;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * REST API and MCP service, each enabled by an environment variable (API_ENABLED, MCP_ENABLED), disabled by default:
 * an instance exposes nothing more than its pages until the administrator decides so. The OAuth authorization server,
 * the personal tokens and the AI agents tab of My account only exist if one of the two is enabled.
 */
final class ApiAvailability
{
    public function __construct(
        #[Autowire('%env(bool:API_ENABLED)%')]
        public readonly bool $api,
        #[Autowire('%env(bool:MCP_ENABLED)%')]
        public readonly bool $mcp,
    ) {}

    public function any(): bool
    {
        return $this->api || $this->mcp;
    }

    /** True if the address is still served: ordinary pages, or the address of an enabled service. */
    public function allows(string $path): bool
    {
        return match (true) {
            str_starts_with($path, '/api/'), $path === '/.well-known/oauth-protected-resource/api' => $this->api,
            $path === '/mcp', $path === '/.well-known/oauth-protected-resource/mcp' => $this->mcp,
            $path === '/.well-known/oauth-protected-resource', $path === '/.well-known/oauth-authorization-server',
            str_starts_with($path, '/oauth/'), $path === '/securite/jetons', str_starts_with($path, '/securite/jetons/'),
            $path === '/securite/agents-ia' => $this->any(),
            default => true,
        };
    }
}
