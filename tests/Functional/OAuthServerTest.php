<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Security\Api\ApiTokens;
use Patrimeter\Tests\Support\ApiTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;

/** Authorization server: the sign-in and its factors are the site's, then consent and PKCE. */
final class OAuthServerTest extends ApiTestCase
{
    private const string REDIRECT = 'http://127.0.0.1:4242/retour';
    private const string VERIFIER = 'un-verificateur-pkce-assez-long-pour-la-rfc-7636';

    public function testMetadataDescribesTheServerAndItsResources(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        self::assertResponseIsSuccessful();
        self::assertSame(['S256'], $this->json()['code_challenge_methods_supported']);
        self::assertTrue($this->json()['authorization_response_iss_parameter_supported']);

        foreach (['/.well-known/oauth-protected-resource' => '/mcp', '/.well-known/oauth-protected-resource/mcp' => '/mcp', '/.well-known/oauth-protected-resource/api' => '/api'] as $path => $resource) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful();
            self::assertSame("http://localhost$resource", $this->json()['resource']);
        }
    }

    public function testRegistrationAcceptsOnlyHttpsOrLoopbackRedirects(): void
    {
        foreach (['http://exemple.fr/retour', 'https://exemple.fr/retour#fragment', 'https://moi@exemple.fr/', 'javascript:alert(1)', 'pas une adresse', "https://exemple.fr/\nretour"] as $uri) {
            $this->register(['redirect_uris' => [$uri]]);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, $uri);
            self::assertSame('invalid_redirect_uri', $this->json()['error']);
        }
        foreach ([[], ['redirect_uris' => []], ['redirect_uris' => 'https://exemple.fr'], ['redirect_uris' => array_fill(0, 6, 'https://exemple.fr/')], ['redirect_uris' => [1]]] as $metadata) {
            $this->register($metadata);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        }

        $this->client->request('POST', '/oauth/register', content: '{pas du json');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_client_metadata', $this->json()['error']);

        $this->register(['redirect_uris' => [self::REDIRECT, 'https://exemple.fr/retour'], 'client_name' => "Nom\x07invalide"]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('Application sans nom', $this->json()['client_name']);
        self::assertSame('none', $this->json()['token_endpoint_auth_method']);
    }

    public function testRegistrationIsLimitedPerIpAddress(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->register(['redirect_uris' => [self::REDIRECT]]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }
        $this->register(['redirect_uris' => [self::REDIRECT]]);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testAuthorizationGoesThroughTheUsualLoginThenConsent(): void
    {
        $client = $this->client();
        $this->client->disableReboot();
        $url = $this->authorizeUrl($client, ['scope' => 'patrimeter.read patrimeter.write']);
        $this->client->request('GET', $url);
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);

        // Usual sign-in: the consent asked for at first comes back at the end, until after the code.
        $this->submitPassword();
        self::assertStringStartsWith('/oauth/authorize?', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirect();
        self::assertResponseRedirects('/connexion/code');
        $this->submitCode(TestUsers::totpCode(static::getContainer()));
        self::assertStringStartsWith('/oauth/authorize?', (string) $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Mon application', $this->body());
        self::assertStringContainsString('127.0.0.1', $this->body());
        self::assertStringContainsString('Créer ou modifier', $this->body());

        $location = $this->decide('allow', $client, ['scope' => 'patrimeter.read patrimeter.write']);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertStringStartsWith(self::REDIRECT . '?', $location);
        self::assertSame('etat-du-client', $query['state']);
        self::assertSame('http://localhost', $query['iss']);
        self::assertIsString($query['code']);
        self::assertEmailCount(1);
        self::assertSame(1, self::queryInt("SELECT COUNT(*) FROM auth_events WHERE type = 'api_granted'"));

        $this->tokenRequest(['grant_type' => 'authorization_code', 'client_id' => $client, 'code' => $query['code'], 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER, 'resource' => 'http://localhost/mcp']);
        self::assertResponseIsSuccessful();
        self::assertSame('Bearer', $this->json()['token_type']);
        self::assertSame('patrimeter.read patrimeter.write', $this->json()['scope']);
        self::assertIsString($this->json()['access_token']);
    }

    public function testRefusingConsentReportsItToTheClient(): void
    {
        $client = $this->client();
        $this->client->disableReboot();
        $this->logIn();

        $location = $this->decide('deny', $client);

        self::assertStringContainsString('error=access_denied', $location);
        self::assertStringContainsString('iss=http%3A%2F%2Flocalhost', $location);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM oauth_codes'));
    }

    public function testUnknownClientOrRedirectIsNeverRedirectedTo(): void
    {
        $client = $this->client();
        $this->client->disableReboot();
        $this->logIn();
        foreach ([$this->authorizeUrl('client-inconnu'), $this->authorizeUrl($client, ['redirect_uri' => 'https://attaquant.example/'])] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
            self::assertStringNotContainsString('attaquant', (string) $this->client->getResponse()->headers->get('Location'));
        }
        $this->submit('/oauth/authorize', ['client_id' => 'client-inconnu', 'redirect_uri' => self::REDIRECT, 'decision' => 'allow'], '/securite');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    /** @return iterable<string, array{array<string, string>, string}> */
    public static function badAuthorizationRequests(): iterable
    {
        yield 'type de réponse' => [['response_type' => 'token'], 'unsupported_response_type'];
        yield 'sans PKCE' => [['code_challenge' => ''], 'invalid_request'];
        yield 'PKCE en clair' => [['code_challenge_method' => 'plain'], 'invalid_request'];
        yield 'portée inconnue' => [['scope' => 'admin'], 'invalid_scope'];
        yield 'ressource étrangère' => [['resource' => 'https://ailleurs.example/mcp'], 'invalid_target'];
    }

    /** @param array<string, string> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('badAuthorizationRequests')]
    public function testBadRequestsAreReportedToTheRegisteredRedirect(array $override, string $error): void
    {
        $client = $this->client();
        $this->client->disableReboot();
        $this->logIn();

        $this->client->request('GET', $this->authorizeUrl($client, $override));

        self::assertResponseStatusCodeSame(Response::HTTP_SEE_OTHER);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        self::assertStringStartsWith(self::REDIRECT, $location);
        self::assertStringContainsString("error=$error", $location);
    }

    public function testLoopbackRedirectMayUseAnotherPortButNotAnotherPath(): void
    {
        $client = $this->client();
        $this->client->disableReboot();
        $this->logIn();

        $this->client->request('GET', $this->authorizeUrl($client, ['redirect_uri' => 'http://127.0.0.1:5555/retour']));
        self::assertResponseIsSuccessful();
        foreach (['http://127.0.0.1:5555/autre', 'http://10.0.0.1:4242/retour', 'https://127.0.0.1:4242/retour'] as $uri) {
            $this->client->request('GET', $this->authorizeUrl($client, ['redirect_uri' => $uri]));
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, $uri);
        }
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function badExchanges(): iterable
    {
        yield 'vérificateur PKCE faux' => [['code_verifier' => 'un-autre-verificateur-pkce-assez-long-pour-la-rfc']];
        yield 'vérificateur trop court' => [['code_verifier' => 'court']];
        yield 'adresse de retour différente' => [['redirect_uri' => 'http://127.0.0.1:9/retour']];
        yield 'ressource différente' => [['resource' => 'http://localhost/api']];
        yield 'code inconnu' => [['code' => 'pm1.' . '0' . '00000000000000000000000.AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA']];
        yield 'code mal formé' => [['code' => 'nimporte-quoi']];
    }

    /** @param array<string, string> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('badExchanges')]
    public function testACodeIsOnlyExchangedWithItsExactParameters(array $override): void
    {
        [$client, $code] = $this->authorizedCode();

        $this->tokenRequest($override + ['grant_type' => 'authorization_code', 'client_id' => $client, 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER, 'resource' => 'http://localhost/mcp']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame('invalid_grant', $this->json()['error']);
    }

    public function testACodeServesOnlyOnceAndNotForAnotherClientOrAfterItsExpiry(): void
    {
        [$client, $code] = $this->authorizedCode();
        $exchange = ['grant_type' => 'authorization_code', 'client_id' => $client, 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER];

        $other = $this->client('Autre');
        $this->tokenRequest(['client_id' => $other] + $exchange);
        self::assertSame('invalid_grant', $this->json()['error']);

        self::clock()->sleep(5);
        $this->tokenRequest($exchange);
        self::assertResponseIsSuccessful();
        self::clock()->sleep(2);
        $this->tokenRequest($exchange);
        self::assertSame('invalid_grant', $this->json()['error'], 'code déjà utilisé');
    }

    public function testAnExpiredCodeIsRefused(): void
    {
        [$client, $code] = $this->authorizedCode();
        self::service(Connection::class)->executeStatement("UPDATE oauth_codes SET expires_at = '2020-01-01T00:00:00Z'");

        $this->tokenRequest(['grant_type' => 'authorization_code', 'client_id' => $client, 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER]);

        self::assertSame('invalid_grant', $this->json()['error']);
    }

    public function testTokenEndpointRefusesUnknownClientsAndGrantsThenThrottles(): void
    {
        $this->client->disableReboot();
        $this->tokenRequest(['grant_type' => 'authorization_code', 'client_id' => 'inconnu']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame('invalid_client', $this->json()['error']);

        $client = $this->client();
        self::clock()->sleep(5);
        $this->tokenRequest(['grant_type' => 'password', 'client_id' => $client]);
        self::assertSame('unsupported_grant_type', $this->json()['error']);

        // An unknown client and four refused exchanges: five failures for this address.
        for ($attempt = 1; $attempt <= 4; ++$attempt) {
            $this->tokenRequest(['grant_type' => 'refresh_token', 'client_id' => $client, 'refresh_token' => 'faux']);
            self::assertSame('invalid_grant', $this->json()['error']);
        }
        $this->tokenRequest(['grant_type' => 'refresh_token', 'client_id' => $client, 'refresh_token' => 'faux']);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testRefreshTurnsAndAReplayRevokesTheWholeGrant(): void
    {
        [$client, $tokens] = $this->exchanged();
        $refresh = $tokens['refresh_token'];

        $this->tokenRequest(['grant_type' => 'refresh_token', 'client_id' => $client, 'refresh_token' => $refresh]);
        self::assertResponseIsSuccessful();
        $next = $this->json();
        self::assertIsString($next['access_token']);
        self::assertNotSame($tokens['access_token'], $next['access_token']);
        $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $next['access_token']], content: 'x');
        self::assertNotSame(Response::HTTP_UNAUTHORIZED, $this->client->getResponse()->getStatusCode(), 'nouveau jeton valable');

        self::clock()->sleep(5);
        $this->tokenRequest(['grant_type' => 'refresh_token', 'client_id' => $client, 'refresh_token' => $refresh]);
        self::assertSame('invalid_grant', $this->json()['error']);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM api_grants'), 'autorisation retirée');
    }

    public function testRefreshIsRefusedForAnotherClientAfterExpiryOrWhenSessionsWereClosed(): void
    {
        [$client, $tokens] = $this->exchanged();
        $other = $this->client('Autre');
        $this->tokenRequest(['grant_type' => 'refresh_token', 'client_id' => $other, 'refresh_token' => $tokens['refresh_token']]);
        self::assertSame('invalid_grant', $this->json()['error']);

        self::clock()->sleep(5);
        self::service(Connection::class)->executeStatement("UPDATE users SET session_stamp = 'nouveau-tampon'");
        $this->tokenRequest(['grant_type' => 'refresh_token', 'client_id' => $client, 'refresh_token' => $tokens['refresh_token']]);
        self::assertSame('invalid_grant', $this->json()['error']);
    }

    public function testRevocationByTheClient(): void
    {
        [$client, $tokens] = $this->exchanged();

        $this->client->request('POST', '/oauth/revoke', ['client_id' => 'autre-client', 'token' => $tokens['access_token']]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM api_grants'));
        $this->client->request('POST', '/oauth/revoke', ['client_id' => $client, 'token' => 'inconnu']);
        self::assertResponseIsSuccessful();

        $this->client->request('POST', '/oauth/revoke', ['client_id' => $client, 'token' => $tokens['access_token']]);
        self::assertResponseIsSuccessful();
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM api_grants'));
    }

    public function testPersonalTokensFromTheSecurityPage(): void
    {
        $this->client->disableReboot();
        $this->logIn();
        $this->submit('/securite/jetons', ['name' => 'Script', 'days' => '90'], '/securite');
        self::assertResponseRedirects('/securite/agents-ia', Response::HTTP_SEE_OTHER, 'identité à confirmer d\'abord');

        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite/agents-ia'], '/securite');
        foreach ([['name' => "\xFF", 'days' => '90'], ['name' => 'Script', 'days' => '7']] as $invalid) {
            $this->submit('/securite/jetons', $invalid, '/securite');
            self::assertResponseRedirects('/securite/agents-ia', Response::HTTP_SEE_OTHER);
        }
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM api_grants'));

        $this->submit('/securite/jetons', ['name' => 'Script', 'days' => '90', 'write' => '1'], '/securite');
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $token = $this->client->getCrawler()->filter('.secret-key code')->text();
        self::assertStringStartsWith('patrimeter_', $token);

        $this->client->request('GET', '/securite/agents-ia');
        self::assertStringContainsString('Script', $this->body());
        self::assertStringContainsString('lecture et écriture', $this->body());
        self::assertStringNotContainsString($token, $this->body());

        $id = self::service(Connection::class)->fetchOne('SELECT id FROM api_grants');
        self::assertIsString($id);
        $this->submit("/securite/jetons/$id/retirer", [], '/securite');
        self::assertResponseRedirects('/securite/agents-ia', Response::HTTP_SEE_OTHER);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM api_grants'));
        self::assertSame(1, self::queryInt("SELECT COUNT(*) FROM auth_events WHERE type = 'api_revoked'"));
    }

    public function testAnotherUserCannotRevokeAGrant(): void
    {
        $this->personalToken();
        $id = self::service(Connection::class)->fetchOne('SELECT id FROM api_grants');
        self::assertIsString($id);
        TestUsers::create(static::getContainer(), TestUsers::OTHER_EMAIL);
        $this->client->disableReboot();
        $this->logIn(TestUsers::OTHER_EMAIL);

        $this->submit("/securite/jetons/$id/retirer", [], '/securite');

        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM api_grants'));
    }

    public function testExpiredGrantsAreHiddenThenPurged(): void
    {
        $this->personalToken();
        self::service(Connection::class)->executeStatement("UPDATE api_grants SET expires_at = '2020-01-01T00:00:00Z'");

        self::assertSame([], self::service(ApiTokens::class)->grantsOf(self::owner()));
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM api_grants'));
        self::service(ApiTokens::class)->purgeExpired();
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM api_grants'));
    }

    /** @param array<string, mixed> $metadata */
    private function register(array $metadata): void
    {
        $this->client->request('POST', '/oauth/register', content: json_encode($metadata, JSON_THROW_ON_ERROR));
    }

    private function client(string $name = 'Mon application'): string
    {
        self::service(\Patrimeter\Infrastructure\Persistence\OAuthClientRepository::class)->create($id = 'client-' . bin2hex(random_bytes(4)), $name, [self::REDIRECT], '2026-01-01T00:00:00Z');

        return $id;
    }

    /** @param array<string, string> $override */
    private function authorizeUrl(string $client, array $override = []): string
    {
        return '/oauth/authorize?' . http_build_query($override + [
            'response_type' => 'code',
            'client_id' => $client,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => ApiTokens::challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'state' => 'etat-du-client',
            'resource' => 'http://localhost/mcp',
        ]);
    }

    /** @param array<string, string> $override */
    private function decide(string $decision, string $client, array $override = []): string
    {
        $this->submit('/oauth/authorize', $override + [
            'decision' => $decision,
            'response_type' => 'code',
            'client_id' => $client,
            'redirect_uri' => self::REDIRECT,
            'code_challenge' => ApiTokens::challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'state' => 'etat-du-client',
            'resource' => 'http://localhost/mcp',
        ], '/securite');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[http-equiv="refresh"]');

        return (string) $this->client->getCrawler()->filter('a.btn-primary')->attr('href');
    }

    /** @param array<string, string> $parameters */
    private function tokenRequest(array $parameters): void
    {
        $this->client->request('POST', '/oauth/token', $parameters);
    }

    /** @return array{string, string} client and valid authorization code */
    private function authorizedCode(): array
    {
        $client = $this->client();
        $this->client->disableReboot();
        $this->logIn();
        $location = $this->decide('allow', $client);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertIsString($query['code']);
        $this->client->getCookieJar()->clear();

        return [$client, $query['code']];
    }

    /** @return array{string, array{access_token: string, refresh_token: string}} */
    private function exchanged(): array
    {
        [$client, $code] = $this->authorizedCode();
        $this->tokenRequest(['grant_type' => 'authorization_code', 'client_id' => $client, 'code' => $code, 'redirect_uri' => self::REDIRECT, 'code_verifier' => self::VERIFIER]);
        self::assertResponseIsSuccessful();
        $access = $this->json()['access_token'];
        $refresh = $this->json()['refresh_token'];
        self::assertIsString($access);
        self::assertIsString($refresh);
        // Meant for MCP: it is the code's resource.
        return [$client, ['access_token' => $access, 'refresh_token' => $refresh]];
    }
}
