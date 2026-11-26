<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/** Simulated answers of the OAuth client metadata documents (no network call in tests). */
final class ClientMetadataResponses
{
    public const string CLIENT = 'https://client.example/oauth/client.json';
    public const string REDIRECT = 'https://client.example/retour';

    /** @var list<string> addresses asked for */
    public array $requests = [];

    /** @var array<string, string> address => body returned (200); other address: 404. By default, a valid client. */
    public array $documents;

    public function __construct()
    {
        $this->documents = [self::CLIENT => json_encode(['client_id' => self::CLIENT, 'client_name' => 'Client par document', 'redirect_uris' => [self::REDIRECT]], JSON_THROW_ON_ERROR)];
    }

    public function __invoke(string $method, string $url): ResponseInterface
    {
        $this->requests[] = $url;

        return isset($this->documents[$url]) ? new MockResponse($this->documents[$url], ['response_headers' => ['content-type' => 'application/json']]) : new MockResponse('', ['http_code' => 404]);
    }
}
