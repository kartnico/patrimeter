<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

use Patrimeter\Application\Calendar;
use Patrimeter\Infrastructure\Mail\AccountMailer;
use Patrimeter\Infrastructure\Persistence\OAuthClientRepository;
use Patrimeter\Security\AuthJournal;
use Patrimeter\Security\Throttle\AuthThrottle;
use Patrimeter\Security\User;
use Patrimeter\Security\Vault\Vault;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

use function Symfony\Component\Translation\t;

/**
 * OAuth 2.1 authorization server of the API and MCP (OAuthServerController shows it over HTTP): metadata, dynamic
 * registration of public clients, consent turned into a code bound to PKCE, and the token endpoint. The user signs in
 * the usual way (password and TOTP, or passkey) before consenting.
 */
final class AuthorizationServer
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
        private readonly AuthJournal $journal,
        private readonly AccountMailer $mailer,
        private readonly Vault $vault,
    ) {}

    /** @return array<string, mixed> RFC 8414 metadata */
    public function serverMetadata(): array
    {
        $issuer = $this->resources->issuer();

        return [
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
        ];
    }

    /** @return array<string, mixed> RFC 9728 metadata of the MCP service */
    public function mcpMetadata(): array
    {
        return $this->resourceMetadata($this->resources->mcp());
    }

    /** @return array<string, mixed> RFC 9728 metadata of the REST API */
    public function apiMetadata(): array
    {
        return $this->resourceMetadata($this->resources->api());
    }

    /**
     * Dynamic registration of a public client (RFC 7591), limited per IP address.
     *
     * @param array<mixed>|null $metadata JSON body of the request, null if it is not a JSON object
     *
     * @return array<string, mixed> the client registered
     *
     * @throws OAuthError
     */
    public function register(string $ip, ?array $metadata): array
    {
        if ($this->throttle->registrationRetryAfter($ip) > 0) {
            throw new OAuthError('temporarily_unavailable', 'Trop d\'inscriptions depuis cette adresse.', Response::HTTP_TOO_MANY_REQUESTS);
        }
        if ($metadata === null) {
            throw new OAuthError('invalid_client_metadata', 'Objet JSON attendu.');
        }
        $uris = self::redirectUris($metadata['redirect_uris'] ?? null);
        $name = isset($metadata['client_name']) && is_string($metadata['client_name']) ? trim($metadata['client_name']) : '';
        if (preg_match(self::CLIENT_NAME_PATTERN, $name) !== 1) {
            $name = self::DEFAULT_CLIENT_NAME;
        }
        $this->throttle->recordRegistration($ip);
        $clientId = Uuid::v7()->toRfc4122();
        $this->clients->create($clientId, $name, $uris, $this->calendar->timestamp());

        return [
            'client_id' => $clientId,
            'client_id_issued_at' => $this->calendar->now()->getTimestamp(),
            'client_name' => $name,
            'redirect_uris' => $uris,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    /**
     * The client of the request, if it is known and the return address is one of its own: registered (RFC 7591), or
     * described by a metadata document, read again (one hour cache) and saved so that codes and authorizations refer
     * to it. Otherwise nothing can be returned to it.
     *
     * @return array{client_id: string, client_name: string, redirect_uris: list<string>}|null
     */
    public function client(AuthorizationRequest $authorization): ?array
    {
        $client = $this->knownClient($authorization->clientId);

        return $client !== null && ClientRedirect::isRegistered($authorization->redirectUri, $client['redirect_uris']) ? $client : null;
    }

    /**
     * Consent given: a code bound to the user's data key, recorded in the sign-in log and told to the user by email.
     *
     * @return string where to send the user back, the code attached
     */
    public function grant(User $user, AuthorizationRequest $authorization, string $clientName, Request $request): string
    {
        $code = $this->tokens->issueCode(
            $user,
            $authorization->clientId,
            $authorization->redirectUri,
            $authorization->codeChallenge,
            (string) ApiScope::parse($authorization->scope),
            (string) $this->resources->normalize($authorization->resource),
            $this->vault->key($user->id),
        );
        $this->journal->record($user->id, AuthJournal::API_GRANTED, $request);
        if ($user->email !== null) {
            $this->mailer->send($user->email, t('Patrimeter : accès accordé à une application'), 'security_notice', ['event' => t('L\'application « %name% » a reçu l\'accès à vos données. Vous pouvez le retirer depuis la page Mon compte.', ['%name%' => $clientName])]);
        }

        return $this->returnUrl($authorization, ['code' => $code]);
    }

    /**
     * Return address of the client with the answer (code or error), the state and the issuer (RFC 9207).
     *
     * @param array<string, string> $answer
     */
    public function returnUrl(AuthorizationRequest $authorization, array $answer): string
    {
        $parameters = array_filter($answer + ['state' => $authorization->state], static fn(string $value): bool => $value !== '')
            + ['iss' => $this->resources->issuer()];
        $uri = $authorization->redirectUri;

        return $uri . (str_contains($uri, '?') ? '&' : '?') . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Token endpoint: a code exchanged once (PKCE), or a refresh token rotated. Failures are limited per IP address.
     *
     * @param InputBag<string>|ParameterBag $parameters
     *
     * @return array{access_token: string, token_type: string, expires_in: int, refresh_token: string, scope: string}
     *
     * @throws OAuthError
     */
    public function token(string $ip, InputBag|ParameterBag $parameters): array
    {
        if ($this->throttle->ipRetryAfter($ip) > 0) {
            throw new OAuthError('temporarily_unavailable', 'Trop de tentatives.', Response::HTTP_TOO_MANY_REQUESTS);
        }
        $clientId = $parameters->getString('client_id');
        $client = $this->clients->find($clientId);
        if ($client === null) {
            $this->throttle->recordIpFailure($ip);
            throw new OAuthError('invalid_client', 'Client inconnu.', Response::HTTP_UNAUTHORIZED);
        }
        $resource = $parameters->getString('resource');
        $tokens = match ($parameters->getString('grant_type')) {
            'authorization_code' => $this->tokens->exchangeCode(
                $parameters->getString('code'),
                $clientId,
                $parameters->getString('redirect_uri'),
                $parameters->getString('code_verifier'),
                $resource === '' ? '' : (string) $this->resources->normalize($resource),
                $client['client_name'],
            ),
            'refresh_token' => $this->tokens->refresh($parameters->getString('refresh_token'), $clientId),
            default => throw new OAuthError('unsupported_grant_type', 'Type d\'échange non pris en charge.'),
        };
        if ($tokens === null) {
            $this->throttle->recordIpFailure($ip);
            throw new OAuthError('invalid_grant', 'Code ou jeton invalide, expiré ou déjà utilisé.');
        }

        return $tokens;
    }

    /** RFC 7009: the token falls if it belongs to this client; nothing tells whether it existed. */
    public function revoke(#[\SensitiveParameter] string $token, string $clientId): void
    {
        $this->tokens->revokeByToken($token, $clientId);
    }

    /** @return array{client_id: string, client_name: string, redirect_uris: list<string>}|null */
    private function knownClient(string $clientId): ?array
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

    /**
     * @return list<string> the return addresses of a registration: HTTPS, or HTTP on the loopback, five at most
     *
     * @throws OAuthError
     */
    private static function redirectUris(mixed $redirectUris): array
    {
        if (!is_array($redirectUris) || !array_is_list($redirectUris) || $redirectUris === [] || count($redirectUris) > self::MAX_REDIRECT_URIS) {
            throw new OAuthError('invalid_redirect_uri', 'Adresses de retour manquantes.');
        }
        $uris = [];
        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || !ClientRedirect::isAcceptable($uri)) {
                throw new OAuthError('invalid_redirect_uri', 'Adresse de retour refusée : HTTPS, ou HTTP sur la boucle locale.');
            }
            $uris[] = $uri;
        }

        return $uris;
    }

    /** @return array<string, mixed> */
    private function resourceMetadata(string $resource): array
    {
        return [
            'resource' => $resource,
            'resource_name' => 'Patrimeter',
            'authorization_servers' => [$this->resources->issuer()],
            'scopes_supported' => ApiScope::ALL,
            'bearer_methods_supported' => ['header'],
        ];
    }
}
