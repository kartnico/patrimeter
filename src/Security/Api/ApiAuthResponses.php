<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * 401 and 403 answers of the API firewall (RFC 6750): they tell the clients where to find the
 * authorization server (RFC 9728), and which scope to ask for.
 */
final class ApiAuthResponses implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface, AccessDeniedHandlerInterface
{
    public function __construct(private readonly ApiResource $resources) {}

    #[\Override]
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return $this->unauthorized($request, 'Bearer ');
    }

    #[\Override]
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return $this->unauthorized($request, 'Bearer error="invalid_token", ');
    }

    #[\Override]
    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return $this->insufficientScope($request);
    }

    public function insufficientScope(Request $request): JsonResponse
    {
        return new JsonResponse(
            ['error' => 'insufficient_scope', 'error_description' => 'Ce jeton ne permet pas d\'écrire.'],
            Response::HTTP_FORBIDDEN,
            ['WWW-Authenticate' => sprintf('Bearer error="insufficient_scope", scope="%s", resource_metadata="%s"', ApiScope::READ . ' ' . ApiScope::WRITE, $this->metadata($request))],
        );
    }

    private function unauthorized(Request $request, string $challenge): Response
    {
        return new JsonResponse(
            ['error' => 'invalid_token', 'error_description' => 'Jeton d\'accès absent, invalide ou expiré.'],
            Response::HTTP_UNAUTHORIZED,
            ['WWW-Authenticate' => sprintf('%sresource_metadata="%s", scope="%s"', $challenge, $this->metadata($request), ApiScope::READ)],
        );
    }

    private function metadata(Request $request): string
    {
        return $this->resources->metadataUrl($this->resources->forPath($request->getPathInfo()));
    }
}
