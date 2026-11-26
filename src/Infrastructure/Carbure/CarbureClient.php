<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Carbure;

use Patrimeter\Application\Carbure\CarbureLink;
use Patrimeter\Application\Carbure\CarbureUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client of Carbure's MCP server (https://github.com/ltoinel/Carbure): JSON-RPC 2.0 POST "tools/call" on
 * <instance>/api/mcp, with the user's API token; read-only tools (list_categories, get_budget). Carbure's server has
 * no session: each call stands alone.
 *
 * Defences against requests forged towards the inside (the address comes from the user): a single path called
 * (/api/mcp), with POST, without redirection, bounded delay and answer size, and never the answer sent back as is
 * (CarbureUnavailable only carries fixed reasons). Host restrictions: CarbureBudget.
 */
final class CarbureClient
{
    private const int MAX_BYTES = 1_048_576;
    private const string PROTOCOL_VERSION = '2025-06-18';

    public function __construct(
        #[Autowire(service: 'patrimeter.carbure_http_client')]
        private readonly HttpClientInterface $http,
    ) {}

    /**
     * Result of a Carbure MCP tool, decoded (Carbure sends it as JSON in a text content).
     *
     * @param array<string, int|string> $arguments
     *
     * @return array<mixed>
     *
     * @throws CarbureUnavailable
     */
    public function call(CarbureLink $link, string $tool, array $arguments = []): array
    {
        try {
            $response = $this->http->request('POST', self::endpoint($link), [
                'timeout' => 5,
                'max_duration' => 10,
                'max_redirects' => 0,
                'headers' => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer ' . $link->token,
                    'MCP-Protocol-Version' => self::PROTOCOL_VERSION,
                ],
                'json' => ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments]],
            ]);
            $status = $response->getStatusCode();
            if (in_array($status, [401, 403], true)) {
                throw new CarbureUnavailable(CarbureUnavailable::REFUSED);
            }
            if (in_array($status, [404, 405], true)) {
                throw new CarbureUnavailable(CarbureUnavailable::NOT_FOUND);
            }
            if ($status !== 200) {
                throw new CarbureUnavailable(CarbureUnavailable::UNREACHABLE);
            }
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > self::MAX_BYTES) {
                    $response->cancel();

                    throw new CarbureUnavailable(CarbureUnavailable::INVALID);
                }
            }
        } catch (ExceptionInterface) {
            throw new CarbureUnavailable(CarbureUnavailable::UNREACHABLE);
        }

        return self::result($body);
    }

    /**
     * Address of the MCP service: that of the instance, normalized again (a link saved before 2.25.2 may hold the
     * address of the service itself, …/api/mcp), followed by /api/mcp.
     */
    private static function endpoint(CarbureLink $link): string
    {
        return (CarbureLink::normalizeUrl($link->url) ?? $link->url) . '/api/mcp';
    }

    /**
     * Text content of the JSON-RPC result, decoded: { result: { content: [ { text: "<json>" } ], isError: false } }.
     *
     * @return array<mixed>
     *
     * @throws CarbureUnavailable
     */
    private static function result(string $body): array
    {
        $result = self::object(self::decode($body)['result'] ?? null);
        if (($result['isError'] ?? null) !== false) {
            throw new CarbureUnavailable(CarbureUnavailable::INVALID);
        }
        return self::decode(self::text(self::object(self::object($result['content'] ?? null)[0] ?? null)['text'] ?? null));
    }

    /**
     * @return array<mixed>
     *
     * @throws CarbureUnavailable
     */
    private static function decode(string $json): array
    {
        try {
            return self::object(json_decode($json, true, 32, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            throw new CarbureUnavailable(CarbureUnavailable::INVALID);
        }
    }

    /** @throws CarbureUnavailable */
    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new CarbureUnavailable(CarbureUnavailable::INVALID);
    }

    /**
     * @return array<mixed>
     *
     * @throws CarbureUnavailable
     */
    private static function object(mixed $value): array
    {
        return is_array($value) ? $value : throw new CarbureUnavailable(CarbureUnavailable::INVALID);
    }
}
