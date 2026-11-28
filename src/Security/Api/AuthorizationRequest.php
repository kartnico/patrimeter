<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Parameters of an authorization request: the query of the consent page, then the hidden fields of its form. Only the
 * "code" response with PKCE S256 is accepted, for a scope and a resource that Patrimeter knows.
 */
final class AuthorizationRequest
{
    private const string CODE_CHALLENGE = '/\A[A-Za-z0-9_-]{43,128}\z/';

    private function __construct(
        public readonly string $clientId,
        public readonly string $redirectUri,
        public readonly string $responseType,
        public readonly string $codeChallenge,
        public readonly string $codeChallengeMethod,
        public readonly string $scope,
        public readonly string $state,
        public readonly string $resource,
    ) {}

    /** @param InputBag<string>|ParameterBag $parameters */
    public static function from(InputBag|ParameterBag $parameters): self
    {
        return new self(
            $parameters->getString('client_id'),
            $parameters->getString('redirect_uri'),
            $parameters->getString('response_type'),
            $parameters->getString('code_challenge'),
            $parameters->getString('code_challenge_method'),
            $parameters->getString('scope'),
            $parameters->getString('state'),
            $parameters->getString('resource'),
        );
    }

    /** OAuth error code of a request the client got wrong (returned to it), or null. */
    public function problem(ApiResource $resources): ?string
    {
        return match (true) {
            $this->responseType !== 'code' => 'unsupported_response_type',
            $this->codeChallengeMethod !== 'S256', preg_match(self::CODE_CHALLENGE, $this->codeChallenge) !== 1 => 'invalid_request',
            ApiScope::parse($this->scope) === null => 'invalid_scope',
            $resources->normalize($this->resource) === null => 'invalid_target',
            default => null,
        };
    }

    public function allowsWrite(): bool
    {
        return ApiScope::allowsWrite((string) ApiScope::parse($this->scope));
    }

    /** @return array<string, string> hidden fields of the consent form, for the client given by the registry */
    public function fields(string $clientId): array
    {
        return [
            'client_id' => $clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => 'S256',
            'state' => $this->state,
            'scope' => $this->scope,
            'resource' => $this->resource,
        ];
    }
}
