<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Carbure\CarbureBudget;
use Patrimeter\Application\Carbure\CarbureLink;
use Patrimeter\Application\Carbure\CarbureUnavailable;
use Patrimeter\Infrastructure\Carbure\CarbureClient;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\CarbureLinkRepository;
use Patrimeter\Tests\Support\FakeCarbure;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Carbure: each user links their instance (address and API token), matches their categories, and reads their actual
 * spending against the planned budget. Simulated instance (FakeCarbure), without network.
 */
final class CarbureTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testLinksTheInstanceAndMapsCategoriesByName(): void
    {
        $budget = self::createBudget(self::owner(), 'Alex', 'Logement', 'Loyer', 130_000, '2026-11');

        $this->submit('/budget/carbure', ['url' => FakeCarbure::URL . '/', 'token' => FakeCarbure::TOKEN], '/budget/carbure');

        self::assertResponseRedirects('/budget/carbure', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Carbure relié : 3 catégories de dépenses.', $this->flashes());
        $request = self::fake()->requests[0];
        self::assertSame(FakeCarbure::URL . '/api/mcp', $request['url']);
        self::assertSame('list_categories', $request['tool']);
        self::assertSame('Bearer ' . FakeCarbure::TOKEN, $request['authorization']);
        $link = self::service(CarbureLinkRepository::class)->find(self::owner());
        self::assertNotNull($link);
        self::assertSame([1 => 'Logement', 2 => 'Alimentation', 5 => 'Loisirs'], $link->categories, 'dépenses de premier niveau seulement');
        self::assertSame([1 => $budget['category']], $link->mapping, 'Logement rapproché par son nom');
        self::assertSelectorTextContains('#carbure-link-title + p', 'cbt_…1234');
        self::assertStringNotContainsString(FakeCarbure::TOKEN, $this->body(), 'le jeton n\'est jamais réaffiché');
        self::assertSame('', $this->client->getCrawler()->filter('#carbure-token')->attr('value') ?? '');
    }

    public function testBudgetShowsActualSpendingNextToThePlan(): void
    {
        $budget = self::createBudget(self::owner(), 'Alex', 'Logement', 'Loyer', 130_000, '2026-11');
        $this->submit('/budget/carbure', ['url' => FakeCarbure::URL, 'token' => FakeCarbure::TOKEN], '/budget/carbure');
        // Groceries ignored; Leisure stays without a mapping.
        $this->submit('/budget/carbure/correspondances', ['mapping' => [1 => (string) $budget['category'], 2 => '0', 5 => '']], '/budget/carbure');
        $this->client->followRedirect();
        self::assertStringContainsString('Correspondance des catégories enregistrée.', $this->flashes());

        $crawler = $this->client->request('GET', '/budget?mois=2026-11');

        self::assertResponseIsSuccessful();
        $requests = self::fake()->requests;
        self::assertSame(['month' => 11, 'year' => 2026, 'category_id' => 0], end($requests)['arguments'] ?? null);
        $row = $crawler->filter('.budget-actuals-table tbody tr');
        self::assertCount(1, $row);
        $cells = $row->filter('td')->each(static fn($cell): string => str_replace(["\u{202F}", "\u{00A0}"], ' ', trim($cell->text())));
        // Planned €1,300, actual €1,234.56: €65.44 less than planned.
        self::assertSame(['1 300,00 €', '1 234,56 €', '−65,44 €'], $cells);
        $section = str_replace(["\u{202F}", "\u{00A0}"], ' ', $crawler->filter('.budget-actuals')->text());
        self::assertStringContainsString('80,00 € dépensés dans des catégories de Carbure sans correspondance : Loisirs.', $section);
        self::assertStringNotContainsString('Alimentation', $section, 'catégorie ignorée');
        self::assertStringNotContainsString('Salaire', $section, 'revenus : pas une dépense');
    }

    /**
     * Regression 2.25.2: the address of the MCP service that Carbure shows (…/api/mcp) was kept as is, the client
     * called …/api/mcp/api/mcp and Carbure answered 404, reported as a refused token.
     */
    public function testTheAddressOfTheMcpServiceIsAcceptedAndAnOldLinkStillWorks(): void
    {
        $budget = self::createBudget(self::owner(), 'Alex', 'Logement', 'Loyer', 130_000, '2026-11');
        $this->submit('/budget/carbure', ['url' => FakeCarbure::URL . '/api/mcp', 'token' => FakeCarbure::TOKEN], '/budget/carbure');
        $this->client->followRedirect();
        self::assertStringContainsString('Carbure relié : 3 catégories de dépenses.', $this->flashes());
        self::assertSame(FakeCarbure::URL . '/api/mcp', self::fake()->requests[0]['url']);
        self::assertSame(FakeCarbure::URL, self::service(CarbureLinkRepository::class)->find(self::owner())?->url);

        // A link saved before the fix, with the address of the service: the budget reads it all the same.
        $link = self::service(CarbureLinkRepository::class)->find(self::owner());
        self::service(CarbureLinkRepository::class)->save(self::owner(), new CarbureLink(FakeCarbure::URL . '/api/mcp', FakeCarbure::TOKEN, $link->categories, [1 => $budget['category']]));
        $crawler = $this->client->request('GET', '/budget?mois=2026-11');
        $requests = self::fake()->requests;
        self::assertSame(FakeCarbure::URL . '/api/mcp', end($requests)['url'] ?? null);
        self::assertCount(1, $crawler->filter('.budget-actuals-table tbody tr'));
    }

    public function testAnAddressWithoutCarbureIsToldApartFromARefusedToken(): void
    {
        // No Carbure at this address (another site, or its MCP server disabled): 404.
        self::fake()->status = 404;
        $this->submit('/budget/carbure', ['url' => 'https://pas-carbure.maison.test', 'token' => FakeCarbure::TOKEN], '/budget/carbure');
        $this->client->followRedirect();
        self::assertStringContainsString('Aucun service MCP de Carbure à cette adresse', $this->flashes());
        self::assertStringNotContainsString('refuse le jeton', $this->flashes());
    }

    public function testInvalidSettingsAndUnreachableInstance(): void
    {
        $this->submit('/budget/carbure', ['url' => 'file:///etc/passwd', 'token' => 'pas un jeton'], '/budget/carbure');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('#err-url', 'Adresse invalide');
        self::assertSelectorTextContains('#err-token', 'Jeton invalide');
        self::assertSame([], self::fake()->requests, 'aucune requête vers une adresse refusée');

        self::fake()->status = 401;
        $this->submit('/budget/carbure', ['url' => FakeCarbure::URL, 'token' => FakeCarbure::TOKEN], '/budget/carbure');
        $this->client->followRedirect();
        self::assertStringContainsString('Carbure refuse le jeton d\'API', $this->flashes());
        self::assertStringNotContainsString('erreur interne', $this->body(), 'la réponse de l\'adresse n\'est jamais renvoyée');
        self::assertNull(self::service(CarbureLinkRepository::class)->find(self::owner()));

        // Link saved, then Carbure unreachable: the budget reports it, without failing.
        self::createBudget(self::owner(), 'Alex', 'Logement', 'Loyer', 130_000, '2026-11');
        self::fake()->status = 200;
        $this->submit('/budget/carbure', ['url' => FakeCarbure::URL, 'token' => FakeCarbure::TOKEN], '/budget/carbure');
        self::fake()->status = 500;
        $this->client->request('GET', '/budget');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.budget-actuals .advice.is-warning', 'Carbure ne répond pas à cette adresse.');
    }

    public function testTokenIsKeptWhenLeftEmptyAndTheLinkCanBeDeleted(): void
    {
        $this->submit('/budget/carbure', ['url' => FakeCarbure::URL, 'token' => FakeCarbure::TOKEN], '/budget/carbure');
        $this->submit('/budget/carbure', ['url' => 'https://carbure2.maison.test', 'token' => ''], '/budget/carbure');

        $link = self::service(CarbureLinkRepository::class)->find(self::owner());
        self::assertSame('https://carbure2.maison.test', $link?->url);
        self::assertSame(FakeCarbure::TOKEN, $link->token);

        $rows = self::service(Connection::class)->fetchAllAssociative('SELECT * FROM carbure_links');
        $dump = json_encode($rows, JSON_THROW_ON_ERROR);
        foreach ([FakeCarbure::TOKEN, 'carbure2', 'Logement'] as $clear) {
            self::assertStringNotContainsString($clear, $dump);
        }

        $this->submit('/budget/carbure/supprimer', [], '/budget/carbure');
        self::assertResponseRedirects('/budget/carbure', Response::HTTP_SEE_OTHER);
        self::assertNull(self::service(CarbureLinkRepository::class)->find(self::owner()));
        $this->submit('/budget/carbure/correspondances', ['mapping' => [1 => '0']], '/budget/carbure');
        $this->client->followRedirect();
        self::assertStringContainsString('Reliez d\'abord votre instance Carbure.', $this->flashes());
    }

    public function testAdministratorCanRestrictHosts(): void
    {
        $limiter = self::getContainer()->get('limiter.carbure');
        $restricted = new CarbureBudget(self::service(CarbureLinkRepository::class), self::service(CarbureClient::class), self::service(BudgetRepository::class), $limiter, ' Carbure.Autorise.test , ');

        try {
            $restricted->connect(self::owner(), FakeCarbure::URL, FakeCarbure::TOKEN);
            self::fail('Hôte accepté');
        } catch (CarbureUnavailable $failure) {
            self::assertSame(CarbureUnavailable::HOST_NOT_ALLOWED, $failure->getMessage());
        }
        self::assertSame([], self::fake()->requests);
        self::assertSame('https://carbure.autorise.test', $restricted->connect(self::owner(), 'https://carbure.autorise.test', FakeCarbure::TOKEN)->url);
    }

    public function testRequestsAreRateLimitedPerUser(): void
    {
        $link = new CarbureLink(FakeCarbure::URL, FakeCarbure::TOKEN, [1 => 'Logement']);
        $carbure = self::service(CarbureBudget::class);
        $month = \Patrimeter\Domain\Month::fromString('2026-11');

        for ($i = 0; $i < 30; ++$i) {
            $carbure->actuals(self::owner(), $link, $month);
        }
        $this->expectExceptionMessage(CarbureUnavailable::THROTTLED);
        $carbure->actuals(self::owner(), $link, $month);
    }

    private static function fake(): FakeCarbure
    {
        return self::service(FakeCarbure::class);
    }
}
