<?php

declare(strict_types=1);

namespace Patrimeter\Controller;

use Patrimeter\Application\Calendar;
use Patrimeter\Infrastructure\Mail\AccountMailer;
use Patrimeter\Infrastructure\Persistence\OAuthClientRepository;
use Patrimeter\Security\Api\ApiResource;
use Patrimeter\Security\Api\ApiScope;
use Patrimeter\Security\Api\ApiTokens;
use Patrimeter\Security\Api\ClientMetadataDocuments;
use Patrimeter\Security\Api\ClientRedirect;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\CurrentUser;
use Patrimeter\Security\PasswordLoginAuthenticator;
use Patrimeter\Security\Throttle\AuthThrottle;
use Patrimeter\Security\Vault\Vault;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Translation\t;

/**
 * OAuth 2.1 authorization server of the API and MCP: the user signs in the usual way (password and TOTP, or
 * passkey), consents, and the client gets a code to exchange with PKCE. Public clients only.
 */
final class OAuthServerController extends AbstractController
{
    private const string CLIENT_NAME_PATTERN = '/\A[^\p{Cc}]{1,80}\z/u';
    private const string DEFAULT_CLIENT_NAME = 'Application sans nom';
    private const int MAX_REDIRECT_URIS = 5;

    public function __construct(
        private readonly ApiResource $resources,
        private readonly OAuthClientRepository $clients,
        private readonly ApiTokens $tokens,
        private readonly Calendar $calendar,
        private readonly AuthThrottle $throttle,
        private readonly ClientMetadataDocuments $documents,
    ) {}

    #[Route('/.well-known/oauth-authorization-server', name: 'app_oauth_server_metadata', methods: ['GET'], stateless: true)]
    public function serverMetadata(): JsonResponse
    {
        $issuer = $this->resources->issuer();

        return new JsonResponse([
            'issuer' => $issuer,
            'authorization_endpoint' => "$issuer/oauth/authorize",
            'token_endpoint' => "$issuer/oauth/token",
            'registration_endpoint' => "$issuer/oauth/register",
            'revocation_endpoint' => "$issuer/oauth/revoke",
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'revocation_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => ApiScope::ALL,
            'authorization_response_iss_parameter_supported' => true,
            'client_id_metadata_document_supported' => true,
        ]);
    }

    #[Route('/.well-known/oauth-protected-resource', name: 'app_oauth_resource_metadata', methods: ['GET'], stateless: true)]
    #[Route('/.well-known/oauth-protected-resource/mcp', name: 'app_oauth_resource_metadata_mcp', methods: ['GET'], stateless: true)]
    public function mcpMetadata(): JsonResponse
    {
        return $this->resourceMetadata($this->resources->mcp());
    }

    #[Route('/.well-known/oauth-protected-resource/api', name: 'app_oauth_resource_metadata_api', methods: ['GET'], stateless: true)]
    public function apiMetadata(): JsonResponse
    {
        return $this->resourceMetadata($this->resources->api());
    }

    /** Dynamic registration of a public client (RFC 7591), limited per IP address. */
    #[Route('/oauth/register', name: 'app_oauth_register', methods: ['POST'], stateless: true)]
    public function register(Request $request): JsonResponse
    {
        $ip = PasswordLoginAuthenticator::clientIp($request);
        if ($this->throttle->registrationRetryAfter($ip) > 0) {
            return self::oauthError('temporarily_unavailable', 'Trop d\'inscriptions depuis cette adresse.', Response::HTTP_TOO_MANY_REQUESTS);
        }
        try {
            $metadata = $request->toArray();
        } catch (JsonException) {
            return self::oauthError('invalid_client_metadata', 'Objet JSON attendu.');
        }
        $redirectUris = $metadata['redirect_uris'] ?? null;
        if (!is_array($redirectUris) || !array_is_list($redirectUris) || $redirectUris === [] || count($redirectUris) > self::MAX_REDIRECT_URIS) {
            return self::oauthError('invalid_redirect_uri', 'Adresses de retour manquantes.');
        }
        $uris = [];
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || !ClientRedirect::isAcceptable($uri)) {
                return self::oauthError('invalid_redirect_uri', 'Adresse de retour refusée : HTTPS, ou HTTP sur la boucle locale.');
            }
            $uris[] = $uri;
        }
        $name = isset($metadata['client_name']) && is_string($metadata['client_name']) ? trim($metadata['client_name']) : '';
        if (preg_match(self::CLIENT_NAME_PATTERN, $name) !== 1) {
            $name = self::DEFAULT_CLIENT_NAME;
        }
        $this->throttle->recordRegistration($ip);
        $clientId = Uuid::v7()->toRfc4122();
        $this->clients->create($clientId, $name, $uris, $this->calendar->timestamp());

        return new JsonResponse([
            'client_id' => $clientId,
            'client_id_issued_at' => $this->calendar->now()->getTimestamp(),
            'client_name' => $name,
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ], Response::HTTP_CREATED);
    }

    /** Consent: behind the main firewall, hence after the sign-in and its mandatory steps. */
    #[Route('/oauth/authorize', name: 'app_oauth_authorize', methods: ['GET'])]
    public function authorize(Request $request): Response
    {
        $parameters = $request->query;
        $client = $this->client($parameters->getString('client_id'));
        $redirectUri = $parameters->getString('redirect_uri');
        if ($client === null || !ClientRedirect::isRegistered($redirectUri, $client['redirect_uris'])) {
            return $this->render('oauth/authorize_error.html.twig', [], new Response(status: Response::HTTP_BAD_REQUEST));
        }
        $problem = $this->requestProblem($parameters);
        if ($problem !== null) {
            return new RedirectResponse($this->redirectWith($redirectUri, ['error' => $problem, 'state' => $parameters->getString('state')]), Response::HTTP_SEE_OTHER);
        }

        return $this->render('oauth/authorize.html.twig', [
            'client_name' => $client['client_name'],
            'redirect_host' => (string) parse_url($redirectUri, PHP_URL_HOST),
            'can_write' => ApiScope::allowsWrite((string) ApiScope::parse($parameters->getString('scope'))),
            'fields' => [
                'client_id' => $client['client_id'],
                'redirect_uri' => $redirectUri,
                'response_type' => 'code',
                'code_challenge' => $parameters->getString('code_challenge'),
                'code_challenge_method' => 'S256',
                'state' => $parameters->getString('state'),
                'scope' => $parameters->getString('scope'),
                'resource' => $parameters->getString('resource'),
            ],
        ]);
    }

    /**
     * Answer through an intermediate page, not a redirection: Chromium applies the CSP form-action to the
     * redirections that follow a form, and would refuse the one to the client.
     */
    #[Route('/oauth/authorize', name: 'app_oauth_authorize_submit', methods: ['POST'])]
    public function decide(Request $request, CurrentUser $currentUser, Vault $vault, AuthJournal $journal, AccountMailer $mailer): Response
    {
        $parameters = $request->request;
        $client = $this->client($parameters->getString('client_id'));
        $redirectUri = $parameters->getString('redirect_uri');
        if ($client === null || !ClientRedirect::isRegistered($redirectUri, $client['redirect_uris'])) {
            return $this->render('oauth/authorize_error.html.twig', [], new Response(status: Response::HTTP_BAD_REQUEST));
        }
        $state = $parameters->getString('state');
        $problem = $parameters->getString('decision') === 'allow' ? $this->requestProblem($parameters) : 'access_denied';
        if ($problem !== null) {
            return $this->leave($this->redirectWith($redirectUri, ['error' => $problem, 'state' => $state]), $client['client_name']);
        }
        $user = $currentUser->user();
        $code = $this->tokens->issueCode(
            $user,
            $client['client_id'],
            $redirectUri,
            $parameters->getString('code_challenge'),
            (string) ApiScope::parse($parameters->getString('scope')),
            (string) $this->resources->normalize($parameters->getString('resource')),
            $vault->key($user->id),
        );
        $journal->record($user->id, AuthJournal::API_GRANTED, $request);
        if ($user->email !== null) {
            $mailer->send($user->email, t('Patrimeter : accès accordé à une application'), 'security_notice', ['event' => t('L\'application « %name% » a reçu l\'accès à vos données. Vous pouvez le retirer depuis la page Mon compte.', ['%name%' => $client['client_name']])]);
        }

        return $this->leave($this->redirectWith($redirectUri, ['code' => $code, 'state' => $state]), $client['client_name']);
    }

    #[Route('/oauth/token', name: 'app_oauth_token', methods: ['POST'], stateless: true)]
    public function token(Request $request): JsonResponse
    {
        $ip = PasswordLoginAuthenticator::clientIp($request);
        if ($this->throttle->ipRetryAfter($ip) > 0) {
            return self::oauthError('temporarily_unavailable', 'Trop de tentatives.', Response::HTTP_TOO_MANY_REQUESTS);
        }
        $parameters = $request->request;
        $clientId = $parameters->getString('client_id');
        $client = $this->clients->find($clientId);
        if ($client === null) {
            $this->throttle->recordIpFailure($ip);

            return self::oauthError('invalid_client', 'Client inconnu.', Response::HTTP_UNAUTHORIZED);
        }
        $tokens = match ($parameters->getString('grant_type')) {
            'authorization_code' => $this->tokens->exchangeCode(
                $parameters->getString('code'),
                $clientId,
                $parameters->getString('redirect_uri'),
                $parameters->getString('code_verifier'),
                $parameters->getString('resource') === '' ? '' : (string) $this->resources->normalize($parameters->getString('resource')),
                $client['client_name'],
            ),
            'refresh_token' => $this->tokens->refresh($parameters->getString('refresh_token'), $clientId),
            default => false,
        };
        if ($tokens === false) {
            return self::oauthError('unsupported_grant_type', 'Type d\'échange non pris en charge.');
        }
        if ($tokens === null) {
            $this->throttle->recordIpFailure($ip);

            return self::oauthError('invalid_grant', 'Code ou jeton invalide, expiré ou déjà utilisé.');
        }

        return new JsonResponse($tokens);
    }

    /** RFC 7009: always 200, whether the token existed or not. */
    #[Route('/oauth/revoke', name: 'app_oauth_revoke', methods: ['POST'], stateless: true)]
    public function revoke(Request $request): Response
    {
        $this->tokens->revokeByToken($request->request->getString('token'), $request->request->getString('client_id'));

        return new Response();
    }

    /**
     * Registered client (RFC 7591), or described by a metadata document: that one is read again (one hour cache)
     * and saved, so that codes and authorizations refer to it.
     *
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>}|null
     */
    private function client(string $clientId): ?array
    {
        if (!ClientMetadataDocuments::isDocumentUrl($clientId)) {
            return $this->clients->find($clientId);
        }
        $document = $this->documents->fetch($clientId);
        if ($document === null) {
            return null;
        }
        $this->clients->saveDocumentClient($clientId, $document['client_name'], $document['redirect_uris'], $this->calendar->timestamp());

        return ['client_id' => $clientId] + $document;
    }

    /** @param InputBag<string>|ParameterBag $parameters */
    private function requestProblem(InputBag|ParameterBag $parameters): ?string
    {
        if ($parameters->getString('response_type') !== 'code') {
            return 'unsupported_response_type';
        }
        if ($parameters->getString('code_challenge_method') !== 'S256' || preg_match('/\A[A-Za-z0-9_-]{43,128}\z/', $parameters->getString('code_challenge')) !== 1) {
            return 'invalid_request';
        }
        if (ApiScope::parse($parameters->getString('scope')) === null) {
            return 'invalid_scope';
        }

        return $this->resources->normalize($parameters->getString('resource')) === null ? 'invalid_target' : null;
    }

    /** @param array<string, string> $parameters */
    private function redirectWith(string $redirectUri, array $parameters): string
    {
        $parameters = array_filter($parameters, static fn(string $value): bool => $value !== '') + ['iss' => $this->resources->issuer()];

        return $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    private function leave(string $url, string $clientName): Response
    {
        return $this->render('oauth/leave.html.twig', ['url' => $url, 'title' => t('Autorisation'), 'provider' => $clientName]);
    }

    private function resourceMetadata(string $resource): JsonResponse
    {
        return new JsonResponse([
            'resource' => $resource,
            'resource_name' => 'Patrimeter',
            'authorization_servers' => [$this->resources->issuer()],
            'scopes_supported' => ApiScope::ALL,
            'bearer_methods_supported' => ['header'],
        ]);
    }

    private static function oauthError(string $error, string $description, int $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        return new JsonResponse(['error' => $error, 'error_description' => $description], $status);
    }
}
