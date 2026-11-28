<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;

/**
 * Navigation since 2.11.0: three entries (wealth, budget, monthly entry), monthly entry in two parts, settings in
 * the tabs of My account, savings of the year on the wealth page.
 */
final class NavigationTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testMainNavigationEndsWithTheMonthlyEntryAlwaysPutForward(): void
    {
        $crawler = $this->client->request('GET', '/');

        $links = $crawler->filter('nav.nav > a');
        self::assertSame(['/', '/budget', '/objectifs', '/defis', '/saisie'], $links->each(static fn($link): ?string => $link->attr('href')));
        self::assertSame(['Patrimoine', 'Budget', 'Objectifs', 'Défis', 'Saisie du mois'], $links->filter('.nav-long')->each(static fn($label): string => $label->text()));
        self::assertSame('a', $crawler->filter('nav.nav > *')->last()->nodeName(), 'on the right of every menu, "My assets" included');
        self::assertStringContainsString('nav-entry', (string) $links->last()->attr('class'), 'put forward, entered or not');
        self::assertSame('page', $links->eq(0)->attr('aria-current'));
        self::assertSame('Patrimoine · Patrimeter', $crawler->filter('title')->text());
    }

    public function testTheWealthPageLeadsToTheAccountsThePropertiesAndTheIndicatorsWithoutAnAssetsMenu(): void
    {
        $crawler = $this->client->request('GET', '/');

        self::assertSelectorNotExists('nav.nav details');
        $links = $crawler->filter('.page-head .page-head-links a.btn-small');
        self::assertSame(['/comptes', '/biens', '/indicateurs'], $links->each(static fn($link): ?string => $link->attr('href')));
        self::assertSame(['Comptes', 'Biens immobiliers', 'Indicateurs'], $links->each(static fn($link): string => $link->text()));
    }

    public function testTopBarShowsTheCurrentMonthInItsMiddle(): void
    {
        // Same month on every page, whatever the month shown by the page (an account: without any, no other month).
        self::createAccount('Livret A', 'livret_a', '2020-01-01');
        $crawler = $this->client->request('GET', '/?mois=2025-03');

        $month = $crawler->filter('header.topbar > p.topbar-month time');
        self::assertSame('2026-11', $month->attr('datetime'));
        self::assertSame('Novembre 2026', $month->filter('.topbar-month-long')->text());
        self::assertSame('Nov. 2026', $month->filter('.topbar-month-short')->text());
        self::assertSame('/?mois=2026-11', $crawler->filter('header.topbar > p.topbar-month > a')->attr('href'), 'back to the current month');
        self::assertSame('Mois en cours :', trim($month->filter('.visually-hidden')->text()));
        // Between the navigation and the buttons.
        self::assertSame(['a', 'nav', 'p', 'form'], array_slice($crawler->filter('header.topbar > *')->each(static fn($node): string => $node->nodeName()), 0, 4));

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/connexion');
        self::assertSelectorNotExists('.topbar-month', 'not before signing in');
    }

    public function testMonthlyEntryHasAWealthPartABudgetPartAndTheGoals(): void
    {
        self::createAccount('Livret A', 'livret_a', '2020-01-01');
        $crawler = $this->client->request('GET', '/saisie?mois=2026-10');

        $parts = $crawler->filter('nav.entry-parts a');
        self::assertSame(['/saisie?mois=2026-10', '/budget/saisie?mois=2026-10', '/saisie/objectifs'], $parts->each(static fn($link): ?string => $link->attr('href')));
        self::assertSame('page', $parts->eq(0)->attr('aria-current'));
        self::assertSame('page', $crawler->filter('nav.nav a[href="/saisie"]')->attr('aria-current'));
    }

    public function testTheMonthlyEntryStandsOutWhileTheCurrentMonthIsNotEntered(): void
    {
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('nav.nav a.needs-entry', 'no account: nothing to enter');

        $account = self::createAccount('Livret A', 'livret_a', '2025-01-01');
        $crawler = $this->client->request('GET', '/budget');
        $entry = $crawler->filter('nav.nav a.needs-entry');
        self::assertSame('/saisie', $entry->attr('href'));
        self::assertStringContainsString('(mois à saisir)', $entry->text(), 'said in words, not only shown');

        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$account => '100']], '/saisie');
        $this->client->followRedirect();
        self::assertSelectorNotExists('nav.nav a.needs-entry', 'entered: no more highlight');
    }

    public function testNothingStandsOutWithoutAnAccountActiveThisMonth(): void
    {
        // Closed before November, or opened after it: the monthly form has no line for them.
        $closed = self::createAccount('Ancien livret', 'livret_a', '2024-01-01');
        self::service(AccountRepository::class)->archive(self::owner(), $closed, '2026-09-30');
        self::createAccount('Futur PEA', 'pea', '2027-02-01');
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('nav.nav a.needs-entry');

        self::createAccount('Compte courant', 'compte_courant', '2026-11-02');
        $this->client->request('GET', '/');
        self::assertSelectorExists('nav.nav a.needs-entry', 'opened this month: to enter');
    }

    public function testBudgetSettingsAreTabsOfMyAccount(): void
    {
        // Without a household, its tab offers to create it.
        $crawler = $this->client->request('GET', '/budget/foyer');
        self::assertResponseIsSuccessful();
        self::assertSame('page', $crawler->filter('nav.account-tabs a[href="/budget/foyer"]')->attr('aria-current'));
        self::assertSelectorExists('form[action="/budget/foyer"]');

        $crawler = $this->client->request('GET', '/budget/carbure');
        self::assertSame('page', $crawler->filter('nav.account-tabs a[href="/budget/carbure"]')->attr('aria-current'));
        self::assertSelectorExists('summary.account-toggle[data-current]');
        self::assertSelectorNotExists('nav.nav a[aria-current]');
    }

    public function testWealthPageShowsWhatWasPutIntoEachLevelSinceTheStartOfTheYear(): void
    {
        $livret = self::createAccount('Livret A', 'livret_a', '2024-01-01');
        $pea = self::createAccount('PEA', 'pea', '2024-01-01');
        $statements = self::service(StatementRepository::class);
        $statements->save(self::owner(), $livret, Month::fromString('2025-12'), 1_000_000);
        $statements->save(self::owner(), $livret, Month::fromString('2026-11'), 1_200_000);
        $statements->save(self::owner(), $pea, Month::fromString('2025-12'), 3_000_000);
        $statements->save(self::owner(), $pea, Month::fromString('2026-11'), 2_950_000);

        $crawler = $this->client->request('GET', '/?mois=2026-11');

        $table = $crawler->filter('.allocation table.year-to-date');
        self::assertCount(1, $table, 'under the source of the allocation');
        self::assertStringContainsString('Depuis fin déc. 2025', self::plain($table->filter('thead')->text()));
        self::assertSame('+2 000,00 €', self::plain($table->filter('tr.level-precaution td')->text()));
        self::assertSame('−500,00 €', self::plain($table->filter('tr.level-actions td')->text()));
        self::assertSame('+1 500,00 €', self::plain($table->filter('tfoot td')->text()));
    }

    private static function plain(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(["\u{202F}", "\u{00A0}"], ' ', $text)) ?? '');
    }
}
