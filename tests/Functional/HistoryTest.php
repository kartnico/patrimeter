<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

final class HistoryTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testNoHistoryWithoutStatements(): void
    {
        self::createAccount('PEA', 'pea', '2020-01-01');
        $this->client->request('GET', '/');

        self::assertSelectorNotExists('#historique');
        self::assertSelectorNotExists('script[src*="chart.umd.min"]');
    }

    public function testFormerHistoryPageLeadsToTheBottomOfTheWealthPage(): void
    {
        $this->client->request('GET', '/historique?periode=tout&vue=niveaux');
        self::assertResponseRedirects('/?periode=tout&vue=niveaux#historique', Response::HTTP_MOVED_PERMANENTLY);

        $this->client->request('GET', '/historique?periode=inconnue');
        self::assertResponseRedirects('/?periode=12#historique', Response::HTTP_MOVED_PERMANENTLY);

        $this->client->request('GET', '/historique');
        self::assertResponseRedirects('/#historique', Response::HTTP_MOVED_PERMANENTLY);
    }

    public function testChartDataAndMonthlyTable(): void
    {
        $id = self::createAccount('PEA', 'pea', '2020-01-01');
        $statements = self::service(StatementRepository::class);
        $statements->save(self::owner(), $id, Month::fromString('2024-01'), 100_000);
        $statements->save(self::owner(), $id, Month::fromString('2026-11'), 150_000);

        $crawler = $this->client->request('GET', '/?periode=tout&vue=niveaux');

        self::assertResponseIsSuccessful();
        // The history is the last section of the wealth page; its links keep the month and come back to it.
        self::assertSame('historique', $crawler->filter('main > section')->last()->attr('id'));
        self::assertSame('/?mois=2026-11&periode=24&vue=niveaux#historique', $crawler->filter('#historique .segmented a')->eq(1)->attr('href'));
        self::assertSame('Tout', $crawler->filter('.segmented a[aria-current="true"]')->first()->text());
        self::assertSelectorTextContains('.history-summary', 'Évolution depuis janvier 2024');
        self::assertSelectorTextContains('.history-summary', '+50,0');

        $json = $crawler->filter('script#history-data[type="application/json"]')->text();
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('niveaux', $data['mode']);
        self::assertIsArray($data['total']);
        self::assertCount(35, $data['total'], 'janvier 2024 à novembre 2026');
        self::assertSame(100_000, $data['total'][0]);
        self::assertSame(150_000, $data['total'][34]);
        self::assertCount(35, $crawler->filter('.history-table tbody tr'));
    }

    public function testDefaultPeriodIsTwelveMonths(): void
    {
        $id = self::createAccount('PEA', 'pea', '2020-01-01');
        self::service(StatementRepository::class)->save(self::owner(), $id, Month::fromString('2020-01'), 1);

        $crawler = $this->client->request('GET', '/?periode=inconnue&vue=inconnue');

        self::assertCount(12, $crawler->filter('.history-table tbody tr'));
        self::assertSame(['12 mois', 'Net total'], $crawler->filter('.segmented a[aria-current="true"]')->each(static fn($node): string => $node->text()));
    }

    public function testChartScriptsAreLoadedFromTheApplicationOnly(): void
    {
        self::service(StatementRepository::class)->save(self::owner(), self::createAccount('PEA', 'pea'), Month::fromString('2026-11'), 1);
        $crawler = $this->client->request('GET', '/');

        $sources = $crawler->filter('script[src]')->each(static fn($node): string => (string) $node->attr('src'));
        self::assertNotEmpty($sources);
        foreach ($sources as $source) {
            self::assertStringStartsWith('/assets/', $source, 'aucun CDN');
        }
        self::assertTrue((bool) array_filter($sources, static fn(string $s): bool => str_contains($s, 'chart.umd.min')));
    }
}
