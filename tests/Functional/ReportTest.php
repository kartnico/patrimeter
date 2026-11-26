<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Application\Game\Game;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

final class ReportTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testTheDashboardLeadsToAShareableReportOfTheSameMonth(): void
    {
        $this->givenBalances();

        $crawler = $this->client->request('GET', '/?mois=2026-10');
        $link = $crawler->filter('.page-head a[href^="/rapport"]');
        self::assertSame('/rapport?mois=2026-10', $link->attr('href'));
        self::assertSame('Partager', trim($link->text()));
    }

    public function testReportShowsNetWorthChangesAndLevelsButNoAccountName(): void
    {
        $this->givenBalances();

        $crawler = $this->client->request('GET', '/rapport');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('header.topbar', 'aucune navigation : la carte occupe l\'écran du téléphone');
        self::assertSelectorTextContains('.report-card h1', 'Mon patrimoine · Novembre 2026');
        self::assertSame("11\u{202F}500\u{00A0}€", $crawler->filter('.report-total')->text());
        self::assertSelectorTextContains('.report-variations li:first-child', "+500\u{00A0}€ (+4,5\u{00A0}%) sur 1 mois");
        self::assertSelectorTextContains('.report-variations li:last-child', "+1\u{202F}500\u{00A0}€ (+15,0\u{00A0}%) sur 1 an");
        self::assertSelectorExists('.report-card svg.pyramid');
        self::assertSelectorTextContains('.report-foot', 'Établi avec Patrimeter le 15/11/2026');
        self::assertStringNotContainsString('Livret secret', $this->body(), 'jamais le nom d\'un compte');
        self::assertSame('/rapport?mois=2026-11&montants=0', $crawler->filter('.report-tools a')->first()->attr('href'));
    }

    public function testWithoutAmountsOnlyPercentagesAreShown(): void
    {
        $this->givenBalances();

        $crawler = $this->client->request('GET', '/rapport?montants=0');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.report-total');
        self::assertSelectorNotExists('.report-figures');
        self::assertStringNotContainsString('€', $crawler->filter('.report-card')->html(), 'aucun montant sur la carte');
        self::assertSelectorTextContains('.report-variations li:first-child', "+4,5\u{00A0}% sur 1 mois");
        self::assertSame('Afficher les montants', $crawler->filter('.report-tools a')->first()->text());
    }

    public function testReportOffersToShareItsPictureOnSocialNetworks(): void
    {
        $this->givenBalances();

        $crawler = $this->client->request('GET', '/rapport');

        $panel = $crawler->filter('[data-report-share]');
        self::assertNotNull($panel->attr('hidden'), 'shown by report.js once the picture is drawn');
        self::assertSame('patrimeter-2026-11.png', $panel->attr('data-share-file'));
        self::assertSame('Ma pyramide du patrimoine, novembre 2026, établie avec Patrimeter.', $panel->attr('data-share-text'));
        self::assertSame(
            ['Mastodon', 'X (Twitter)', 'Facebook', 'WhatsApp', 'Instagram'],
            $panel->filter('[data-share-network]')->each(static fn($button): string => trim($button->text())),
        );
        self::assertSame('mastodon-form', $panel->filter('[data-share-network="mastodon"]')->attr('aria-controls'));
        self::assertSelectorExists('#mastodon-form label[for="mastodon-instance"]');
        self::assertStringContainsString('publiée sur un réseau social, elle les rend publics', $panel->filter('.report-share-warning')->text());
        self::assertSame('/rapport?mois=2026-11&montants=0', $panel->filter('.report-share-warning a')->attr('href'));
        self::assertCount(1, $crawler->filter('script[src*="/js/report"]'));
    }

    public function testPictureWithoutAmountsNeedsNoWarning(): void
    {
        $this->givenBalances();

        $crawler = $this->client->request('GET', '/rapport?montants=0');

        self::assertSame('patrimeter-2026-11-pourcentages.png', $crawler->filter('[data-report-share]')->attr('data-share-file'));
        self::assertSelectorNotExists('.report-share-warning');
        self::assertStringNotContainsString('€', $crawler->filter('[data-report-share]')->attr('data-share-text') ?? '');
    }

    public function testTheStarsAndTheLevelAreAddedOnRequestWithoutAnyAmount(): void
    {
        $this->givenBalances();

        $crawler = $this->client->request('GET', '/rapport?montants=0');
        self::assertSelectorNotExists('.report-stars', 'opt-in');
        $link = $crawler->filter('.report-tools a')->reduce(static fn($a): bool => $a->text() === 'Afficher mes étoiles');
        self::assertSame('/rapport?mois=2026-11&montants=0&etoiles=1', $link->attr('href'));

        $crawler = $this->client->request('GET', '/rapport?montants=0&etoiles=1');
        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^★ \d+ étoiles? · niveau \d : \S/u', $crawler->filter('.report-stars')->text());
        self::assertStringNotContainsString('€', $crawler->filter('.report-card')->html(), 'still no amount');
        self::assertSame('/rapport?mois=2026-11&montants=1&etoiles=1', $crawler->filter('.report-tools a')->first()->attr('href'), 'showing the amounts keeps the stars');
        self::assertSame('Masquer mes étoiles', $crawler->filter('.report-tools a')->eq(1)->text());
    }

    public function testWithoutTheChallengesNoStarsAreOffered(): void
    {
        $this->givenBalances();
        self::service(Game::class)->setEnabled(self::owner(), false);

        $crawler = $this->client->request('GET', '/rapport?etoiles=1');

        self::assertSelectorNotExists('.report-stars');
        self::assertCount(0, $crawler->filter('.report-tools a')->reduce(static fn($a): bool => str_contains($a->text(), 'étoiles')));
    }

    public function testMonthWithoutDataAndFutureMonth(): void
    {
        $this->givenBalances();

        $this->client->request('GET', '/rapport?mois=2020-01');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state', 'Aucun solde connu pour janvier 2020.');
        self::assertSelectorNotExists('[data-report-share]');
        self::assertSelectorNotExists('script[src*="/js/report"]');

        $this->client->request('GET', '/rapport?mois=2027-01');
        self::assertResponseRedirects('/rapport', Response::HTTP_SEE_OTHER);
    }

    private function givenBalances(): void
    {
        $id = self::createAccount('Livret secret', 'livret_a', '2020-01-01');
        foreach (['2025-11' => 10_000_00, '2026-10' => 11_000_00, '2026-11' => 11_500_00] as $month => $cents) {
            self::service(StatementRepository::class)->save(self::owner(), $id, Month::fromString($month), $cents);
        }
    }
}
