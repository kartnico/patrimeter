<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\PortfolioService;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\GameRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Security\Api\ApiTokens;
use Patrimeter\Tests\Support\ApiTestCase;
use Patrimeter\Tests\Support\RouteCatalog;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/** REST API: bearer token, scope, data decrypted with the token's key, shared business rules. */
final class ApiTest extends ApiTestCase
{
    private const string MARKER = 'Livret-Secret-5e2c';

    public function testEveryApiRouteRefusesARequestWithoutToken(): void
    {
        $checked = 0;
        foreach (RouteCatalog::routes(self::service(RouterInterface::class)) as $name => $route) {
            if (!in_array($name, RouteCatalog::BEARER, true)) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $response = $this->api($method, RouteCatalog::path($route), null);
                self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), "$method {$route->getPath()}");
                self::assertStringContainsString('resource_metadata="http://localhost/.well-known/oauth-protected-resource/api"', (string) $response->headers->get('WWW-Authenticate'));
                ++$checked;
            }
        }
        self::assertGreaterThanOrEqual(19, $checked);
    }

    public function testAnInvalidTokenIsRefused(): void
    {
        foreach (['inconnu', 'pm1.' . str_repeat('0', 24) . '.' . str_repeat('A', 43), ApiTokens::PREFIX . str_repeat('0', 24) . '_' . str_repeat('A', 43)] as $token) {
            $response = $this->api('GET', '/api/v1/me', $token);
            self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
            self::assertStringContainsString('error="invalid_token"', (string) $response->headers->get('WWW-Authenticate'));
        }
    }

    public function testATokenIsRecognizableAndTheFormerFormatStaysValid(): void
    {
        $token = $this->personalToken();
        self::assertMatchesRegularExpression('/\A' . ApiTokens::PATTERN . '\z/', $token);
        self::assertStringStartsWith('patrimeter_', $token);
        // The rule of the secret detectors is the one of the code.
        $gitleaks = (string) file_get_contents(__DIR__ . '/../../.gitleaks.toml');
        self::assertStringContainsString("regex = '''" . ApiTokens::PATTERN . "'''", $gitleaks);
        self::assertStringContainsString('useDefault = true', $gitleaks);

        self::assertSame(Response::HTTP_OK, $this->api('GET', '/api/v1/me', $token)->getStatusCode());
        // Same selector and secret written in the former format (tokens issued before 2.23.0): still accepted.
        $former = 'pm1.' . substr($token, 11, 24) . '.' . substr($token, 36);
        self::assertMatchesRegularExpression('/\A' . ApiTokens::PATTERN . '\z/', $former);
        self::assertSame(Response::HTTP_OK, $this->api('GET', '/api/v1/me', $former)->getStatusCode());
    }

    public function testATamperedSecretIsRefused(): void
    {
        $token = $this->personalToken();
        $tampered = substr($token, 0, -2) . (str_ends_with($token, 'AA') ? 'BB' : 'AA');

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->api('GET', '/api/v1/me', $tampered)->getStatusCode());
    }

    public function testReadTokenReadsDecryptedDataButCannotWrite(): void
    {
        $account = self::createAccount(self::MARKER, 'livret_a');
        self::service(StatementRepository::class)->save(self::owner(), $account, Month::fromString('2026-11'), 123_456);
        $token = $this->personalToken();

        $this->api('GET', '/api/v1/me', $token);
        self::assertResponseIsSuccessful();
        self::assertSame(self::EMAIL, $this->json()['email']);
        self::assertFalse($this->json()['can_write']);

        $this->api('GET', '/api/v1/accounts', $token);
        self::assertStringContainsString(self::MARKER, $this->body());
        self::assertStringContainsString('"amount_cents":123456', $this->body());

        $this->api('GET', '/api/v1/net-worth?month=2026-11', $token);
        self::assertSame(123_456, $this->json()['net_cents']);
        self::assertSame('2026-11', $this->json()['month']);

        foreach ([['POST', '/api/v1/accounts'], ['PUT', "/api/v1/accounts/$account/statements/2026-11"], ['DELETE', "/api/v1/accounts/$account"]] as [$method, $path]) {
            $response = $this->api($method, $path, $token, []);
            self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode(), "$method $path");
            self::assertStringContainsString('error="insufficient_scope"', (string) $response->headers->get('WWW-Authenticate'));
        }
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM accounts'), 'rien de modifié');
    }

    public function testChallengesAreReadWithTheirNotice(): void
    {
        $account = self::createAccount('Livret A', 'livret_a', '2026-01-01');
        foreach (['2026-09', '2026-10', '2026-11'] as $month) {
            self::service(StatementRepository::class)->save(self::owner(), $account, Month::fromString($month), 100_000);
        }
        $token = $this->personalToken();

        self::assertSame(200, $this->api('GET', '/api/v1/challenges', $token)->getStatusCode());
        $game = $this->json();
        self::assertTrue($game['enabled']);
        self::assertSame(PortfolioService::CHALLENGES_NOTICE, $game['notice']);
        self::assertSame(['months' => 3, 'joker_available' => true], $game['streak']);
        self::assertSame('organized', $game['stage'], 'three complete months');
        self::assertIsArray($game['challenges']);
        $streak = array_values(array_filter($game['challenges'], static fn(mixed $challenge): bool => is_array($challenge) && $challenge['id'] === 'streak'))[0];
        self::assertSame(['id' => 'streak', 'stars' => 1, 'max_stars' => 3, 'value' => 3, 'thresholds' => [3, 6, 12], 'available' => true], $streak);

        // Turned off by the user: nothing else measured, the notice stays.
        self::service(GameRepository::class)->saveEnabled(self::owner(), false);
        $this->api('GET', '/api/v1/challenges', $token);
        self::assertSame(['enabled' => false, 'notice' => PortfolioService::CHALLENGES_NOTICE], $this->json());
    }

    public function testReadingStarsWithATokenLeavesTheAnnouncementForMonthlyEntry(): void
    {
        $account = self::createAccount('Livret A', 'livret_a', '2026-01-01');
        foreach (['2026-09', '2026-10', '2026-11'] as $month) {
            self::service(StatementRepository::class)->save(self::owner(), $account, Month::fromString($month), 100_000);
        }
        $token = $this->personalToken();
        self::assertSame(200, $this->api('GET', '/api/v1/challenges', $token)->getStatusCode());
        $this->logIn();
        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$account => '1000']], '/saisie');
        $this->client->followRedirect();
        self::assertStringContainsString('Nouvelle étoile, défi « Série de saisies » : 1 sur 3.', $this->flashes());
    }

    public function testAccountLifecycleWithAWriteToken(): void
    {
        $token = $this->personalToken(write: true);
        $this->api('GET', '/api/v1/me', $token);
        self::assertTrue($this->json()['can_write']);
        $this->api('GET', '/api/v1/account-types', $token);
        $types = $this->json()['account_types'];
        self::assertIsArray($types);
        self::assertCount(38, $types);
        $type = self::typeId('livret_a');

        $this->api('POST', '/api/v1/accounts', $token, ['name' => self::MARKER, 'type_id' => $type, 'opened_on' => '2025-01-15', 'initial_amount_cents' => 50_000]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->json()['id'];
        self::assertIsInt($id);
        self::assertSame(0, self::queryInt("SELECT COUNT(*) FROM accounts WHERE ciphertext LIKE '%Secret%'"), 'chiffré en base');

        $this->api('PUT', "/api/v1/accounts/$id/statements/2026-10", $token, ['amount_cents' => 60_000]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->api('GET', "/api/v1/accounts/$id/statements", $token);
        self::assertStringContainsString('"amount_cents":60000', $this->body());
        $this->api('DELETE', "/api/v1/accounts/$id/statements/2026-10", $token);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->api('PUT', "/api/v1/accounts/$id", $token, ['name' => 'Renommé', 'type_id' => $type, 'opened_on' => '2026-12-01']);
        self::assertResponseStatusCodeSame(422);
        $this->api('GET', "/api/v1/accounts/$id", $token);
        self::assertSame('2025-01-15', $this->json()['opened_on']);

        $this->api('PUT', "/api/v1/accounts/$id", $token, ['name' => 'Renommé', 'type_id' => $type, 'opened_on' => '2025-01-15']);
        self::assertResponseIsSuccessful();
        self::assertSame('Renommé', $this->json()['name']);

        $this->api('POST', "/api/v1/accounts/$id/archive", $token, ['closed_on' => '2026-11-01']);
        self::assertSame('2026-11-01', $this->json()['closed_on']);
        $this->api('POST', "/api/v1/accounts/$id/unarchive", $token, []);
        self::assertNull($this->json()['closed_on']);

        $this->api('DELETE', "/api/v1/accounts/$id", $token);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->api('GET', "/api/v1/accounts/$id", $token);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertStringContainsString('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));
    }

    public function testPropertySharesLoansAndReports(): void
    {
        $token = $this->personalToken(write: true);
        $house = self::createAccount('Maison', 'lmnp');
        $loan = self::createAccount('Prêt', 'credit_immo_rp');
        $this->api('PUT', "/api/v1/accounts/$house/shares/2025-01", $token, ['share_millionths' => 500_000]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->api('PUT', "/api/v1/accounts/$loan/loan-link", $token, ['asset_id' => $house, 'loan_mode' => 'own']);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->api('PUT', "/api/v1/accounts/$house/statements/2026-11", $token, ['amount_cents' => 20_000_000]);
        $this->api('PUT', "/api/v1/accounts/$loan/statements/2026-11", $token, ['amount_cents' => 1_000_000]);

        $this->api('GET', "/api/v1/accounts/$house/shares", $token);
        self::assertStringContainsString('"share_millionths":500000', $this->body());

        $this->api('GET', '/api/v1/properties?month=2026-11', $token);
        $properties = $this->json()['properties'];
        self::assertIsArray($properties);
        self::assertIsArray($properties[0]);
        self::assertSame(9_000_000, $properties[0]['net_cents'], '50 % du bien, moins le crédit compté en montant propre');

        $this->api('GET', '/api/v1/history?from=2026-10&to=2026-11', $token);
        self::assertIsArray($this->json()['months']);
        self::assertCount(2, $this->json()['months']);
        $this->api('GET', '/api/v1/history', $token);
        self::assertResponseIsSuccessful();

        $this->api('PUT', "/api/v1/accounts/$house", $token, ['name' => 'Maison', 'type_id' => self::typeId('lmnp'), 'level' => 'exotique', 'nature' => 'asset', 'opened_on' => '2025-01-01']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertIsArray($this->json()['errors']);
        self::assertArrayHasKey('level_code', $this->json()['errors']);

        $this->api('DELETE', "/api/v1/accounts/$house/shares/2025-01", $token);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->api('PUT', "/api/v1/accounts/$loan/loan-link", $token, ['asset_id' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>|null, string}> */
    public static function invalidRequests(): iterable
    {
        yield 'mois de rapport' => ['GET', '/api/v1/net-worth?month=2026-13', null, 'month'];
        yield 'mois des biens' => ['GET', '/api/v1/properties?month=abc', null, 'month'];
        yield 'mois d\'historique' => ['GET', '/api/v1/history?from=abc', null, 'from'];
        yield 'compte sans nom' => ['POST', '/api/v1/accounts', ['name' => '', 'type_id' => 1, 'opened_on' => '2025-01-01'], 'name'];
        yield 'solde initial non entier' => ['POST', '/api/v1/accounts', ['name' => 'A', 'type_id' => 1, 'opened_on' => '2025-01-01', 'initial_amount_cents' => 1.5], 'initial_amount_cents'];
        yield 'montant décimal' => ['PUT', '/api/v1/accounts/{id}/statements/2026-11', ['amount_cents' => 1.5], 'amount_cents'];
        yield 'montant démesuré' => ['PUT', '/api/v1/accounts/{id}/statements/2026-11', ['amount_cents' => 10 ** 16], 'amount_cents'];
        yield 'compte pas encore ouvert' => ['PUT', '/api/v1/accounts/{id}/statements/2024-01', ['amount_cents' => 1], 'month'];
        yield 'clôture invalide' => ['POST', '/api/v1/accounts/{id}/archive', ['closed_on' => 'hier'], 'closed_on'];
        yield 'clôture avant l\'ouverture' => ['POST', '/api/v1/accounts/{id}/archive', ['closed_on' => '2020-01-01'], 'closed_on'];
        yield 'part hors d\'un bien' => ['PUT', '/api/v1/accounts/{id}/shares/2026-01', ['share_millionths' => 500_000], 'month'];
        yield 'lien vers un non-bien' => ['PUT', '/api/v1/accounts/{id}/loan-link', ['asset_id' => 999_999], 'asset_id'];
    }

    /** @param array<string, mixed>|null $body */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRequests')]
    public function testInvalidDataIsRefusedFieldByField(string $method, string $path, ?array $body, string $field): void
    {
        $token = $this->personalToken(write: true);
        $id = self::createAccount('Compte', 'livret_a');

        $this->api($method, str_replace('{id}', (string) $id, $path), $token, $body);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertIsArray($this->json()['errors']);
        self::assertArrayHasKey($field, $this->json()['errors']);
    }

    public function testShareOutOfRangeAndMalformedJson(): void
    {
        $token = $this->personalToken(write: true);
        $house = self::createAccount('Maison', 'lmnp');

        $this->api('PUT', "/api/v1/accounts/$house/shares/2026-01", $token, ['share_millionths' => 1_000_001]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->client->request('POST', '/api/v1/accounts', server: ['HTTP_AUTHORIZATION' => "Bearer $token"], content: '{pas du json');
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertStringContainsString('application/problem+json', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $this->api('GET', '/api/v1/inexistant', $token);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testEachTokenIsRateLimited(): void
    {
        $token = $this->personalToken();
        $other = $this->personalToken();
        $limiter = static::getContainer()->get('limiter.api_token');
        // The allowed rate used up at once (120 requests in a row).
        $limiter->create(hash('sha256', $token))->consume(120);

        $limited = null;
        for ($i = 0; $i < 5 && $limited === null; ++$i) {
            $response = $this->api('GET', '/api/v1/me', $token);
            $limited = $response->getStatusCode() === Response::HTTP_TOO_MANY_REQUESTS ? $response : null;
        }
        self::assertNotNull($limited, '429 une fois le débit épuisé');
        self::assertGreaterThanOrEqual(1, (int) $limited->headers->get('Retry-After'));
        self::assertSame('application/problem+json', $limited->headers->get('Content-Type'));

        $this->client->request('POST', '/mcp', server: ['HTTP_AUTHORIZATION' => "Bearer $token", 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], content: '{"jsonrpc": "2.0", "id": 1, "method": "ping"}');
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS, 'MCP compris');

        $this->api('GET', '/api/v1/me', $other);
        self::assertResponseIsSuccessful('chaque jeton a son propre débit');
    }

    public function testATokenFallsWithTheSessionsOfItsUser(): void
    {
        $token = $this->personalToken();
        self::assertResponseIsSuccessfulOf($this->api('GET', '/api/v1/me', $token));

        self::service(Connection::class)->executeStatement("UPDATE users SET session_stamp = 'nouveau-tampon' WHERE id = ?", [self::owner()->value]);

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->api('GET', '/api/v1/me', $token)->getStatusCode());
    }

    public function testATokenExpires(): void
    {
        $token = $this->personalToken();
        self::service(Connection::class)->executeStatement("UPDATE api_tokens SET expires_at = '2020-01-01T00:00:00Z'");

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->api('GET', '/api/v1/me', $token)->getStatusCode());
    }

    public function testATokenIsBoundToItsResource(): void
    {
        $forMcp = $this->resourceToken(self::mcpResource());

        self::assertSame(Response::HTTP_UNAUTHORIZED, $this->api('GET', '/api/v1/me', $forMcp)->getStatusCode());
    }

    public function testNeitherTheTokenNorTheDataKeyIsStored(): void
    {
        $token = $this->personalToken();
        $secret = substr($token, 36);
        $row = self::service(Connection::class)->fetchAssociative('SELECT * FROM api_tokens');
        self::assertIsArray($row);
        self::assertStringNotContainsString($secret, json_encode($row, JSON_THROW_ON_ERROR));
        self::assertIsString($row['wrapped_key']);
        self::assertStringStartsWith('v1.', $row['wrapped_key'], 'clé de données enveloppée');
    }

    private static function typeId(string $code): int
    {
        $id = self::service(Connection::class)->fetchOne('SELECT id FROM account_types WHERE code = ?', [$code]);
        self::assertIsInt($id);

        return $id;
    }

    private static function assertResponseIsSuccessfulOf(Response $response): void
    {
        self::assertTrue($response->isSuccessful(), (string) $response->getContent());
    }
}
