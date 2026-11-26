<?php

declare(strict_types=1);

namespace Patrimeter\Security\Api;

use Patrimeter\Domain\Account;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth Client ID Metadata Documents, which the MCP specification prefers to dynamic registration: the
 * client_id is the HTTPS address of a JSON document that describes the client (name, redirect addresses).
 *
 * The document is fetched by Symfony's HttpClient behind NoPrivateNetworkHttpClient (no address of the local
 * network nor of the loopback: no forged request towards the inside), without redirection, 64 KB at most,
 * and kept one hour in cache. It is read by Symfony's serializer and checked by its constraints.
 */
final class ClientMetadataDocuments
{
    private const int MAX_BYTES = 65536;
    private const int TTL = 3600;
    private const int FAILURE_TTL = 60;
    private const string DEFAULT_NAME = 'Application sans nom';

    public function __construct(
        #[Autowire(service: 'patrimeter.client_metadata_http_client')]
        private readonly HttpClientInterface $http,
        #[Autowire(service: 'cache.app')]
        private readonly CacheInterface $cache,
        private readonly ValidatorInterface $validator,
        private readonly SerializerInterface $serializer,
    ) {}

    /** True if the identifier points to a document: HTTPS, with a path, without fragment nor credentials. */
    public static function isDocumentUrl(string $clientId): bool
    {
        $parts = parse_url($clientId);

        return $parts !== false && ($parts['scheme'] ?? '') === 'https' && isset($parts['host']) && ($parts['path'] ?? '/') !== '/'
            && !isset($parts['fragment']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    /** @return array{client_name: string, redirect_uris: list<string>}|null description of the client, or null if unusable */
    public function fetch(string $clientId): ?array
    {
        if (!self::isDocumentUrl($clientId)) {
            return null;
        }

        /** @var array{client_name: string, redirect_uris: list<string>}|null $document */
        $document = $this->cache->get('client_metadata_' . hash('sha256', $clientId), function (ItemInterface $item) use ($clientId): ?array {
            $document = $this->download($clientId);
            $item->expiresAfter($document === null ? self::FAILURE_TTL : self::TTL);

            return $document;
        });

        return $document;
    }

    /** @return array{client_name: string, redirect_uris: list<string>}|null */
    private function download(string $url): ?array
    {
        try {
            $response = $this->http->request('GET', $url, [
                'timeout' => 5,
                'max_duration' => 10,
                'max_redirects' => 0,
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $body = '';
            foreach ($this->http->stream($response) as $chunk) {
                $body .= $chunk->getContent();
                if (strlen($body) > self::MAX_BYTES) {
                    $response->cancel();

                    return null;
                }
            }
            $document = $this->serializer->deserialize($body, ClientMetadataDocument::class, 'json');
        } catch (ExceptionInterface|SerializerExceptionInterface) {
            return null;
        }
        // The document must name itself (protection against a copied document).
        if ($document->client_id !== $url || count($this->validator->validate($document)) > 0) {
            return null;
        }
        $name = trim($document->client_name ?? '');

        return [
            'client_name' => preg_match(Account::NAME_PATTERN, $name) === 1 ? $name : self::DEFAULT_NAME,
            'redirect_uris' => $document->redirect_uris,
        ];
    }

    public static function checkRedirect(mixed $uri, \Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if (!is_string($uri) || !ClientRedirect::isAcceptable($uri)) {
            $context->addViolation('Adresse de retour refusée.');
        }
    }
}
