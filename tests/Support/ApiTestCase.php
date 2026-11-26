<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

use Patrimeter\Domain\UserId;
use Patrimeter\Security\Api\ApiResource;
use Patrimeter\Security\Api\ApiScope;
use Patrimeter\Security\Api\ApiTokens;
use Patrimeter\Security\UserProvider;
use Patrimeter\Security\Vault\Vault;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requests with a bearer token. Each request starts from a new kernel, as in production: neither token nor data key
 * survives from one request to the next.
 */
abstract class ApiTestCase extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->client->enableReboot();
    }

    /** Personal token of an existing user: valid for the API and MCP. */
    protected function personalToken(bool $write = false, ?UserId $owner = null): string
    {
        $owner ??= self::owner();
        TestUsers::unlock(static::getContainer(), $owner);
        $user = self::service(UserProvider::class)->loadUserByIdentifier($owner->value);

        return self::service(ApiTokens::class)->createPersonal($user, 'Jeton de test', $write ? ApiScope::READ . ' ' . ApiScope::WRITE : ApiScope::READ, 30, self::service(Vault::class)->key($owner));
    }

    /** Token meant for a single resource, like those from the OAuth flow. */
    protected function resourceToken(string $resource, bool $write = true): string
    {
        $owner = self::owner();
        TestUsers::unlock(static::getContainer(), $owner);
        $user = self::service(UserProvider::class)->loadUserByIdentifier($owner->value);
        self::service(\Patrimeter\Infrastructure\Persistence\OAuthClientRepository::class)->create('client-de-test', 'Client de test', ['https://client.example/retour'], '2026-01-01T00:00:00Z');
        $verifier = str_repeat('v', 43);
        $code = self::service(ApiTokens::class)->issueCode($user, 'client-de-test', 'https://client.example/retour', ApiTokens::challenge($verifier), $write ? ApiScope::READ . ' ' . ApiScope::WRITE : ApiScope::READ, $resource, self::service(Vault::class)->key($owner));
        $tokens = self::service(ApiTokens::class)->exchangeCode($code, 'client-de-test', 'https://client.example/retour', $verifier, $resource, 'Client de test');
        self::assertNotNull($tokens);

        return $tokens['access_token'];
    }

    protected static function mcpResource(): string
    {
        return self::service(ApiResource::class)->mcp();
    }

    protected static function apiResource(): string
    {
        return self::service(ApiResource::class)->api();
    }

    /** @param array<string, mixed>|null $body */
    protected function api(string $method, string $path, ?string $token, ?array $body = null): Response
    {
        $this->client->request(
            $method,
            $path,
            server: ($token === null ? [] : ['HTTP_AUTHORIZATION' => "Bearer $token"]) + ['CONTENT_TYPE' => 'application/json'],
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        return $this->client->getResponse();
    }

    /** @return array<string, mixed> */
    protected function json(): array
    {
        $decoded = json_decode($this->body(), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> */
        return $decoded;
    }
}
