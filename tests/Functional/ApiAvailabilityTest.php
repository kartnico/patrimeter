<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Http\ApiAvailability;
use Patrimeter\Tests\Support\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * REST API and MCP service, disabled by default (API_ENABLED, MCP_ENABLED): a disabled service answers 404 on all its
 * addresses, and the OAuth server and the personal tokens only exist if one of the two is enabled. The tests enable
 * both (.env.test); these ones disable them in turn.
 */
final class ApiAvailabilityTest extends FunctionalTestCase
{
    /** @return iterable<string, array{bool, bool, string, bool}> API, MCP, address, served */
    public static function paths(): iterable
    {
        foreach ([[false, false], [true, false], [false, true], [true, true]] as [$api, $mcp]) {
            $label = ($api ? 'API' : 'sans API') . ', ' . ($mcp ? 'MCP' : 'sans MCP');
            yield "$label : API" => [$api, $mcp, '/api/v1/me', $api];
            yield "$label : métadonnées de l'API" => [$api, $mcp, '/.well-known/oauth-protected-resource/api', $api];
            yield "$label : MCP" => [$api, $mcp, '/mcp', $mcp];
            yield "$label : métadonnées du MCP" => [$api, $mcp, '/.well-known/oauth-protected-resource/mcp', $mcp];
            yield "$label : serveur OAuth" => [$api, $mcp, '/.well-known/oauth-authorization-server', $api || $mcp];
            yield "$label : jeton OAuth" => [$api, $mcp, '/oauth/token', $api || $mcp];
            yield "$label : jetons personnels" => [$api, $mcp, '/securite/jetons/3/retirer', $api || $mcp];
            yield "$label : onglet Agents IA" => [$api, $mcp, '/securite/agents-ia', $api || $mcp];
        }
        yield 'pages ordinaires' => [false, false, '/connexion', true];
        yield 'adresse voisine' => [false, false, '/apiculture', true];
    }

    #[DataProvider('paths')]
    public function testPathsFollowTheSwitches(bool $api, bool $mcp, string $path, bool $served): void
    {
        self::assertSame($served, (new ApiAvailability($api, $mcp))->allows($path));
    }

    public function testDisabledServicesAnswerNotFoundAndHideTokens(): void
    {
        self::getContainer()->set(ApiAvailability::class, new ApiAvailability(false, false));

        $this->client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('POST', '/mcp', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/.well-known/oauth-authorization-server');
        self::assertResponseStatusCodeSame(404);

        $this->logIn();
        $crawler = $this->client->request('GET', '/securite');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextNotContains('main', 'API et applications');
        self::assertSelectorTextNotContains('main', 'agents IA');
        self::assertSame([], $crawler->filter('a[href="/securite/agents-ia"]')->each(static fn($link): string => $link->text()), 'ni onglet ni entrée de menu');
        $this->client->request('GET', '/securite/agents-ia');
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyTheMcpServiceIsAnnounced(): void
    {
        self::getContainer()->set(ApiAvailability::class, new ApiAvailability(false, true));
        $this->logIn();

        $crawler = $this->client->request('GET', '/securite/agents-ia');

        self::assertSelectorTextContains('main', 'Sur cette instance, seul le service MCP est activé.');
        self::assertSame(['Adresse du service MCP'], $crawler->filter('main dt')->each(static fn($term): string => $term->text()), 'adresse du MCP seulement');
        self::assertSelectorTextContains('main dd code', 'http://localhost/mcp');
        // Copy button next to each address, hidden until app.js finds a clipboard it may write to.
        $button = $crawler->filter('main dd button[data-copy]');
        self::assertCount(1, $button);
        self::assertSame('mcp-url', $button->attr('data-copy'));
        self::assertSame('http://localhost/mcp', trim($crawler->filter('#mcp-url')->text()));
        self::assertNotNull($button->attr('hidden'));
        self::assertSame('Copier l\'adresse du service MCP', $button->attr('aria-label'));
        self::assertSelectorExists('a[href="https://kartnico.github.io/patrimeter/fr/utilisation/api/"]');
        $this->client->request('GET', '/api/v1/me');
        self::assertResponseStatusCodeSame(404);
    }
}
