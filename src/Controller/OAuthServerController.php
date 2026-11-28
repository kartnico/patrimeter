<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Security\Api\ApiResource;
use Patrimeter\Security\Api\AuthorizationRequest;
use Patrimeter\Security\Api\AuthorizationServer;
use Patrimeter\Security\Api\OAuthError;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\PasswordLoginAuthenticator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

use function Symfony\Component\Translation\t;

/**
 * HTTP side of the OAuth 2.1 authorization server of the API and MCP (AuthorizationServer): metadata, registration,
 * consent page and token endpoints. Public clients only, PKCE S256.
 */
final class OAuthServerController extends AbstractController
{
    public function __construct(
        private readonly AuthorizationServer $server,
        private readonly ApiResource $resources,
    ) {}

    #[Route('/.well-known/oauth-authorization-server', name: 'app_oauth_server_metadata', methods: ['GET'], stateless: true)]
    public function serverMetadata(): JsonResponse
    {
        return new JsonResponse($this->server->serverMetadata());
    }

    #[Route('/.well-known/oauth-protected-resource', name: 'app_oauth_resource_metadata', methods: ['GET'], stateless: true)]
    #[Route('/.well-known/oauth-protected-resource/mcp', name: 'app_oauth_resource_metadata_mcp', methods: ['GET'], stateless: true)]
    public function mcpMetadata(): JsonResponse
    {
        return new JsonResponse($this->server->mcpMetadata());
    }

    #[Route('/.well-known/oauth-protected-resource/api', name: 'app_oauth_resource_metadata_api', methods: ['GET'], stateless: true)]
    public function apiMetadata(): JsonResponse
    {
        return new JsonResponse($this->server->apiMetadata());
    }

    /** Dynamic registration of a public client (RFC 7591), limited per IP address. */
    #[Route('/oauth/register', name: 'app_oauth_register', methods: ['POST'], stateless: true)]
    public function register(Request $request): JsonResponse
    {
        try {
            $metadata = $request->toArray();
        } catch (JsonException) {
            $metadata = null;
        }
        try {
            return new JsonResponse($this->server->register(PasswordLoginAuthenticator::clientIp($request), $metadata), Response::HTTP_CREATED);
        } catch (OAuthError $error) {
            return self::error($error);
        }
    }

    /** Consent: behind the main firewall, hence after the sign-in and its mandatory steps. */
    #[Route('/oauth/authorize', name: 'app_oauth_authorize', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        $authorization = AuthorizationRequest::from($request->query);
        $client = $this->server->client($authorization);
        if ($client === null) {
            return $this->render('oauth/authorize_error.html.twig', [], new Response(status: Response::HTTP_BAD_REQUEST));
        }
        $problem = $authorization->problem($this->resources);
        if ($problem !== null) {
            return new RedirectResponse($this->server->returnUrl($authorization, ['error' => $problem]), Response::HTTP_SEE_OTHER);
        }

        return $this->render('oauth/authorize.html.twig', [
            'client_name' => $client['client_name'],
            'redirect_host' => (string) parse_url($authorization->redirectUri, PHP_URL_HOST),
            'can_write' => $authorization->allowsWrite(),
            'fields' => $authorization->fields($client['client_id']),
        ]);
    }

    /**
     * Answer through an intermediate page, not a redirection: Chromium applies the CSP form-action to the
     * redirections that follow a form, and would refuse the one to the client.
     */
    #[Route('/oauth/authorize', name: 'app_oauth_authorize_submit', methods: ['POST'])]
    public function decide(Request $request, CurrentUser $currentUser): Response
    {
        $authorization = AuthorizationRequest::from($request->request);
        $client = $this->server->client($authorization);
        if ($client === null) {
            return $this->render('oauth/authorize_error.html.twig', [], new Response(status: Response::HTTP_BAD_REQUEST));
        }
        $problem = $request->request->getString('decision') === 'allow' ? $authorization->problem($this->resources) : 'access_denied';
        $url = $problem === null
            ? $this->server->grant($currentUser->user(), $authorization, $client['client_name'], $request)
            : $this->server->returnUrl($authorization, ['error' => $problem]);

        return $this->render('oauth/leave.html.twig', ['url' => $url, 'title' => t('Autorisation'), 'provider' => $client['client_name']]);
    }

    #[Route('/oauth/token', name: 'app_oauth_token', methods: ['POST'], stateless: true)]
    public function token(Request $request): JsonResponse
    {
        try {
            return new JsonResponse($this->server->token(PasswordLoginAuthenticator::clientIp($request), $request->request));
        } catch (OAuthError $error) {
            return self::error($error);
        }
    }

    /** RFC 7009: always 200, whether the token existed or not. */
    #[Route('/oauth/revoke', name: 'app_oauth_revoke', methods: ['POST'], stateless: true)]
    public function revoke(Request $request): Response
    {
        $this->server->revoke($request->request->getString('token'), $request->request->getString('client_id'));

        return new Response();
    }

    private static function error(OAuthError $error): JsonResponse
    {
        return new JsonResponse($error->body(), $error->status);
    }
}
