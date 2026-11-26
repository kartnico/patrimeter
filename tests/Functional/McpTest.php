<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Mcp\Exception\ToolCallException;
use Patrimeter\Application\Mcp\PortfolioTools;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Security\UserProvider;
use Patrimeter\Tests\Support\ApiTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** MCP service (symfony/mcp-bundle) behind the API firewall: same tokens, same data. */
final class McpTest extends ApiTestCase
{
    private const string MODERN = '2026-07-28';
    private const string SECRET = 'Livret-Secret-5e2c';

    public function testWithoutTokenTheClientLearnsWhereToAuthenticate(): void
    {
        $this->client->request('POST', '/mcp', content: '{}');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $challenge = (string) $this->client->getResponse()->headers->get('WWW-Authenticate');
        self::assertStringContainsString('resource_metadata="http://localhost/.well-known/oauth-protected-resource/mcp"', $challenge);
        self::assertStringContainsString('scope="patrimeter.read"', $challenge);
    }

    /** Against DNS rebinding (MCP specification): a web page of another origin gets a 403, even with a valid token. */
    public function testAForeignOriginIsRefused(): void
    {
        $token = $this->resourceToken(self::mcpResource());
        $send = function (?string $origin) use ($token): int {
            $server = self::headers($token, 'tools/list', self::MODERN) + ($origin === null ? [] : ['HTTP_ORIGIN' => $origin]);
            $this->client->request('POST', '/mcp', server: $server, content: self::message('tools/list', [], self::MODERN));

            return $this->client->getResponse()->getStatusCode();
        };

        foreach (['https://evil.example', 'http://localhost.evil.example', 'http://localhost:8080', 'null'] as $origin) {
            self::assertSame(Response::HTTP_FORBIDDEN, $send($origin), $origin);
            $problem = json_decode($this->body(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($problem);
            self::assertSame('Origine non autorisée', $problem['title'] ?? null);
        }
        // No Origin (client outside a browser), the site itself, and the origins of MCP_ALLOWED_ORIGINS (.env.test).
        foreach ([null, 'http://localhost', 'https://agent.example.test', 'http://127.0.0.1:6274'] as $origin) {
            self::assertSame(Response::HTTP_OK, $send($origin), (string) $origin . ' ' . $this->body());
        }
        // The API itself is not concerned: it answers browsers through its own rules.
        $this->client->request('GET', '/api/v1/me', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $this->personalToken(), 'HTTP_ORIGIN' => 'https://evil.example']);
        self::assertResponseIsSuccessful();
    }

    public function testATokenForTheApiAloneIsRefused(): void
    {
        $token = $this->resourceToken(self::apiResource());

        $this->rpc($token, 'server/discover', [], self::MODERN);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testModernDiscoveryAndToolList(): void
    {
        $token = $this->resourceToken(self::mcpResource());

        $this->rpc($token, 'server/discover', [], self::MODERN);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(self::MODERN, $this->body());
        self::assertStringContainsString('Patrimeter', $this->body());

        $this->rpc($token, 'tools/list', [], self::MODERN);
        self::assertResponseIsSuccessful();
        foreach (['get_net_worth', 'get_history', 'list_accounts', 'get_account', 'list_properties', 'list_account_types', 'list_goals', 'get_budget', 'get_challenges', ...PortfolioTools::WRITE_TOOLS] as $tool) {
            self::assertStringContainsString("\"$tool\"", $this->body());
        }
    }

    public function testToolsReturnDecryptedDataOfTheTokenOwner(): void
    {
        $account = self::createAccount(self::SECRET, 'livret_a');
        self::service(StatementRepository::class)->save(self::owner(), $account, Month::fromString('2026-11'), 123_456);
        $token = $this->personalToken();

        foreach ([['get_net_worth', ['month' => '2026-11'], '123456'], ['list_accounts', [], self::SECRET], ['get_account', ['account_id' => $account], '123456'], ['get_history', ['from' => '2026-11', 'to' => '2026-11'], '123456'], ['list_properties', [], 'properties'], ['list_account_types', [], 'livret_a'], ['list_goals', ['month' => '2026-11'], 'goals'], ['get_budget', [], 'household'], ['get_challenges', [], 'pas un conseil en investissement']] as [$tool, $arguments, $expected]) {
            $this->callTool($token, $tool, $arguments);
            self::assertResponseIsSuccessful();
            self::assertStringContainsString($expected, $this->body(), $tool);
            self::assertStringNotContainsString('"isError":true', $this->body(), $tool);
        }
    }

    public function testBusinessErrorsBecomeToolErrors(): void
    {
        $token = $this->personalToken(write: true);

        $this->callTool($token, 'get_net_worth', ['month' => 'pas-un-mois']);
        self::assertStringContainsString('"isError":true', $this->body());
        self::assertStringContainsString('Mois invalide', $this->body());

        $this->callTool($token, 'get_account', ['account_id' => 999_999]);
        self::assertStringContainsString('"isError":true', $this->body());
        self::assertStringContainsString('Compte introuvable', $this->body());
    }

    public function testAnotherUsersAccountIsUnreachable(): void
    {
        $victim = self::createAccount(self::SECRET, 'livret_a');
        $intruder = TestUsers::create(static::getContainer(), TestUsers::OTHER_EMAIL);
        $token = $this->personalToken(write: true, owner: $intruder);

        foreach ([['get_account', ['account_id' => $victim]], ['save_statement', ['account_id' => $victim, 'month' => '2026-11', 'amount_cents' => 1]], ['delete_statement', ['account_id' => $victim, 'month' => '2026-11']]] as [$tool, $arguments]) {
            $this->callTool($token, $tool, $arguments);
            self::assertStringContainsString('Compte introuvable', $this->body(), $tool);
            self::assertStringNotContainsString(self::SECRET, $this->body());
        }
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM statements WHERE account_id = ?', [$victim]));
    }

    public function testReadTokenGetsAScopeChallengeOnWriteTools(): void
    {
        $account = self::createAccount('Compte', 'livret_a');
        $token = $this->personalToken();

        $this->callTool($token, 'save_statement', ['account_id' => $account, 'month' => '2026-11', 'amount_cents' => 1]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $challenge = (string) $this->client->getResponse()->headers->get('WWW-Authenticate');
        self::assertStringContainsString('error="insufficient_scope"', $challenge);
        self::assertStringContainsString('patrimeter.write', $challenge);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM statements'));
    }

    public function testWriteToolsWithAWriteToken(): void
    {
        $token = $this->personalToken(write: true);
        $this->client->request('GET', '/api/v1/account-types', server: ['HTTP_AUTHORIZATION' => "Bearer $token"]);
        $types = $this->json()['account_types'];
        self::assertIsArray($types);
        self::assertIsArray($types[0]);

        $this->callTool($token, 'create_account', ['name' => self::SECRET, 'type_id' => $types[0]['id'], 'opened_on' => '2025-01-01', 'initial_amount_cents' => 100]);
        self::assertStringNotContainsString('"isError":true', $this->body());
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM accounts'));
        $id = self::queryInt('SELECT id FROM accounts');
        self::assertSame(0, self::queryInt("SELECT COUNT(*) FROM accounts WHERE ciphertext LIKE '%Secret%'"), 'chiffré en base');

        $this->callTool($token, 'save_statement', ['account_id' => $id, 'month' => '2026-10', 'amount_cents' => 500]);
        self::assertStringContainsString('saved', $this->body());
        self::assertSame(2, self::queryInt('SELECT COUNT(*) FROM statements'));

        $this->callTool($token, 'delete_statement', ['account_id' => $id, 'month' => '2026-10']);
        self::assertStringContainsString('deleted', $this->body());
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM statements'));
    }

    public function testTheToolItselfRefusesWritingWithoutTheRole(): void
    {
        $user = self::service(UserProvider::class)->loadUserByIdentifier(self::owner()->value);
        self::service(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'api', $user->getRoles()));

        $this->expectException(ToolCallException::class);
        self::service(PortfolioTools::class)->saveStatement(1, '2026-11', 1);
    }

    public function testForeignOriginIsRefused(): void
    {
        $token = $this->personalToken();

        $this->client->request('POST', '/mcp', server: self::headers($token, 'server/discover', self::MODERN) + ['HTTP_ORIGIN' => 'https://attaquant.example'], content: self::message('server/discover', [], self::MODERN));

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testLegacyClientsInitializeThenCallTools(): void
    {
        $token = $this->personalToken();
        $this->client->request('POST', '/mcp', server: self::headers($token), content: json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass(), 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"protocolVersion":"2025-06-18"', $this->body());
        $session = (string) $this->client->getResponse()->headers->get('Mcp-Session-Id');
        self::assertNotSame('', $session);

        $this->client->request('POST', '/mcp', server: self::headers($token) + ['HTTP_MCP_SESSION_ID' => $session, 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'], content: json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);

        $this->client->request('POST', '/mcp', server: self::headers($token) + ['HTTP_MCP_SESSION_ID' => $session, 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18'], content: json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('get_net_worth', $this->body());
    }

    /** @param array<string, mixed> $arguments */
    private function callTool(string $token, string $tool, array $arguments): void
    {
        $this->rpc($token, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments], self::MODERN);
    }

    /** @param array<string, mixed> $params */
    private function rpc(string $token, string $method, array $params, string $version): void
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : null;
        $this->client->request('POST', '/mcp', server: self::headers($token, $method, $version, $name), content: self::message($method, $params, $version));
    }

    /** @return array<string, string> */
    private static function headers(string $token, ?string $method = null, ?string $version = null, ?string $name = null): array
    {
        return array_filter([
            'HTTP_AUTHORIZATION' => "Bearer $token",
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_MCP_PROTOCOL_VERSION' => $version,
            'HTTP_MCP_METHOD' => $method,
            'HTTP_MCP_NAME' => $name,
        ], static fn(?string $value): bool => $value !== null);
    }

    /** @param array<string, mixed> $params */
    private static function message(string $method, array $params, string $version): string
    {
        return json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => $method,
            'params' => $params + ['_meta' => [
                'io.modelcontextprotocol/protocolVersion' => $version,
                'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1'],
                'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
