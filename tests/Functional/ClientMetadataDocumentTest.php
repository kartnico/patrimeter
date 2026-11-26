<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Security\Api\ApiTokens;
use Patrimeter\Security\Api\ClientMetadataDocuments;
use Patrimeter\Tests\Support\ApiTestCase;
use Patrimeter\Tests\Support\ClientMetadataResponses;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validation;

/** OAuth clients described by a metadata document (Client ID Metadata Documents). */
final class ClientMetadataDocumentTest extends ApiTestCase
{
    private const string VERIFIER = 'un-verificateur-pkce-assez-long-pour-la-rfc-7636';

    public function testServerAdvertisesSupport(): void
    {
        $this->client->request('GET', '/.well-known/oauth-authorization-server');

        self::assertTrue($this->json()['client_id_metadata_document_supported']);
    }

    public function testAClientDescribedByItsDocumentGetsTokens(): void
    {
        $this->client->disableReboot();
        $this->logIn();
        $query = $this->query();

        $this->client->request('GET', '/oauth/authorize?' . http_build_query($query));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Client par document', $this->body());

        $this->submit('/oauth/authorize', $query + ['decision' => 'allow'], '/securite');
        $back = (string) $this->client->getCrawler()->filter('a.btn-primary')->attr('href');
        parse_str((string) parse_url($back, PHP_URL_QUERY), $parameters);
        self::assertStringStartsWith(ClientMetadataResponses::REDIRECT, $back);
        self::assertIsString($parameters['code']);

        $this->client->getCookieJar()->clear();
        $this->client->request('POST', '/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => ClientMetadataResponses::CLIENT, 'code' => $parameters['code'], 'redirect_uri' => ClientMetadataResponses::REDIRECT, 'code_verifier' => self::VERIFIER]);
        self::assertResponseIsSuccessful();
        $token = $this->json()['access_token'];
        self::assertIsString($token);
        self::assertStringStartsWith('patrimeter_', $token);
    }

    /** @return iterable<string, array{string|null}> */
    public static function unusableDocuments(): iterable
    {
        yield 'document absent' => [null];
        yield 'pas du JSON' => ['<html>'];
        yield 'autre client_id' => [json_encode(['client_id' => 'https://autre.example/client.json', 'redirect_uris' => [ClientMetadataResponses::REDIRECT]], JSON_THROW_ON_ERROR)];
        yield 'sans adresse de retour' => [json_encode(['client_id' => ClientMetadataResponses::CLIENT, 'redirect_uris' => []], JSON_THROW_ON_ERROR)];
        yield 'adresses de retour mal typées' => [json_encode(['client_id' => ClientMetadataResponses::CLIENT, 'redirect_uris' => [42]], JSON_THROW_ON_ERROR)];
        yield 'sans client_id' => [json_encode(['redirect_uris' => [ClientMetadataResponses::REDIRECT]], JSON_THROW_ON_ERROR)];
        yield 'adresse de retour en HTTP public' => [json_encode(['client_id' => ClientMetadataResponses::CLIENT, 'redirect_uris' => ['http://client.example/retour']], JSON_THROW_ON_ERROR)];
        yield 'trop volumineux' => [json_encode(['client_id' => ClientMetadataResponses::CLIENT, 'redirect_uris' => [ClientMetadataResponses::REDIRECT], 'remplissage' => str_repeat('a', 70_000)], JSON_THROW_ON_ERROR)];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableDocuments')]
    public function testAnUnusableDocumentIsRefusedWithoutRedirecting(?string $document): void
    {
        $responses = self::service(ClientMetadataResponses::class);
        $responses->documents = $document === null ? ['https://ailleurs.example/' => ''] : [ClientMetadataResponses::CLIENT => $document];
        $this->client->disableReboot();
        $this->logIn();

        $this->client->request('GET', '/oauth/authorize?' . http_build_query($this->query()));

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM oauth_clients'));
    }

    public function testOnlyHttpsUrlsWithAPathAreDocuments(): void
    {
        self::assertTrue(ClientMetadataDocuments::isDocumentUrl(ClientMetadataResponses::CLIENT));
        foreach (['http://client.example/client.json', 'https://client.example', 'https://client.example/', 'https://moi@client.example/c.json', 'https://client.example/c.json#x', '0190-identifiant'] as $id) {
            self::assertFalse(ClientMetadataDocuments::isDocumentUrl($id), $id);
        }
    }

    public function testPrivateNetworksAreNeverFetched(): void
    {
        $inner = new MockHttpClient(static fn(): MockResponse => new MockResponse(json_encode(['client_id' => 'https://127.0.0.1/c.json', 'redirect_uris' => ['https://127.0.0.1/r']], JSON_THROW_ON_ERROR)));
        $documents = new ClientMetadataDocuments(new NoPrivateNetworkHttpClient($inner), new ArrayAdapter(), Validation::createValidator(), self::service(SerializerInterface::class));

        self::assertNull($documents->fetch('https://127.0.0.1/c.json'));
        self::assertNull($documents->fetch('https://[::1]/c.json'));
        self::assertSame(0, $inner->getRequestsCount(), 'requête bloquée avant tout envoi');
    }

    /** @return array<string, string> */
    private function query(): array
    {
        return [
            'response_type' => 'code',
            'client_id' => ClientMetadataResponses::CLIENT,
            'redirect_uri' => ClientMetadataResponses::REDIRECT,
            'code_challenge' => ApiTokens::challenge(self::VERIFIER),
            'code_challenge_method' => 'S256',
            'state' => 'etat',
            'resource' => 'http://localhost/mcp',
        ];
    }
}
