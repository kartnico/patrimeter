<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Simulated Carbure instance (MCP server, no network call in tests): spending categories Housing (1), Groceries (2)
 * and Leisure (5), an income category (3) and a subcategory (4); spending of the month.
 */
final class FakeCarbure
{
    public const string URL = 'https://carbure.maison.test';
    public const string TOKEN = 'cbt_jetondetestjetondetest1234';

    /** @var list<array{url: string, tool: mixed, arguments: mixed, authorization: string}> */
    public array $requests = [];

    /** HTTP code returned (200: answer of the tool). */
    public int $status = 200;

    /** @var list<array<string, mixed>> */
    public array $categories = [
        ['id' => 1, 'name' => 'Logement', 'parent' => null, 'type' => 'DEBIT'],
        ['id' => 2, 'name' => 'Alimentation', 'parent' => null, 'type' => 'DEBIT'],
        ['id' => 3, 'name' => 'Salaire', 'parent' => null, 'type' => 'CREDIT'],
        ['id' => 4, 'name' => 'Électricité', 'parent' => 'Logement', 'type' => 'DEBIT'],
        ['id' => 5, 'name' => 'Loisirs', 'parent' => null, 'type' => 'DEBIT'],
    ];

    /** @var list<array<string, mixed>> spending of the month (get_budget), in euros */
    public array $budget = [
        ['id' => 1, 'name' => 'Logement', 'budget' => 1300, 'consummed' => 1234.56],
        ['id' => 2, 'name' => 'Alimentation', 'budget' => 500, 'consummed' => 450.1],
        ['id' => 3, 'name' => 'Salaire', 'budget' => 0, 'consummed' => 3000],
        ['id' => 5, 'name' => 'Loisirs', 'budget' => 100, 'consummed' => 80],
    ];

    /** @param array<string, mixed> $options */
    public function __invoke(string $method, string $url, array $options = []): ResponseInterface
    {
        $body = is_string($options['body'] ?? null) ? json_decode($options['body'], true) : null;
        $params = is_array($body) && is_array($body['params'] ?? null) ? $body['params'] : [];
        $authorization = '';
        foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $header) {
            if (is_string($header) && str_starts_with(strtolower($header), 'authorization:')) {
                $authorization = trim(substr($header, strlen('authorization:')));
            }
        }
        $this->requests[] = ['url' => $url, 'tool' => $params['name'] ?? null, 'arguments' => $params['arguments'] ?? null, 'authorization' => $authorization];
        // Like Carbure: its MCP service only answers at <instance>/api/mcp, never at …/api/mcp/api/mcp.
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (!str_ends_with($path, '/api/mcp') || substr_count($path, '/api/mcp') > 1) {
            return new MockResponse('{"error":"Not Found"}', ['http_code' => 404]);
        }
        if ($this->status !== 200) {
            return new MockResponse('<html>erreur interne</html>', ['http_code' => $this->status]);
        }
        $data = match ($params['name'] ?? null) {
            'list_categories' => $this->categories,
            'get_budget' => $this->budget,
            default => null,
        };
        $result = $data === null
            ? ['content' => [['type' => 'text', 'text' => 'Unknown tool']], 'isError' => true]
            : ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_THROW_ON_ERROR)]], 'isError' => false];

        return new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => 1, 'result' => $result], JSON_THROW_ON_ERROR), ['response_headers' => ['content-type' => 'application/json']]);
    }
}
