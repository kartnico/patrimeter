<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;

final class DashboardTest extends FunctionalTestCase
{
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->logIn();
    }

    public function testTheLogoLeadsBackToTheDashboard(): void
    {
        $this->client->request('GET', '/comptes');

        self::assertSelectorExists('header a.brand[href="/"]');
        self::assertSelectorNotExists('a[href="#contenu"]', 'pas de lien « Aller au contenu » : le logo ramène à l\'accueil');
    }

    public function testWelcomeWhenThereIsNoAccount(): void
    {
        $this->client->request('GET', '/');

        self::assertSelectorTextContains('.welcome h2', 'Bienvenue dans Patrimeter');
        // Four ways to start, the pyramid built level by level first.
        self::assertSame(
            ['/bienvenue/pyramide', '/bienvenue/questions', '/bienvenue/express', '/donnees#csv', '/comptes/nouveau'],
            $this->client->getCrawler()->filter('.welcome-choices a')->each(static fn($link): ?string => $link->attr('href')),
        );
        self::assertSelectorExists('.welcome-choice.is-default[href="/bienvenue/pyramide"]');
        self::assertSelectorExists('.welcome a[href="/biens/nouveau"]');
    }

    public function testNetWorthPyramidAndComparisons(): void
    {
        $this->givenBalances(['2025-11' => 10_000_00, '2026-10' => 11_000_00, '2026-11' => 11_500_00]);

        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Novembre 2026');
        // Each level: its tier of the pyramid (class pyr-<level>) and its lines in the other boxes (data-level), for the
        // highlight (app.js).
        self::assertCount(1, $crawler->filter('[data-level-highlight] svg.pyramid g.pyr-tier.pyr-precaution'));
        self::assertCount(1, $crawler->filter('details.level-detail[data-level="precaution"]'));
        self::assertSame("11\u{202F}500,00\u{00A0}€", trim($crawler->filter('.summary-total')->text()));
        $variations = $crawler->filter('.summary-variation')->each(static fn($node): string => preg_replace('/[ \t\r\n]+/', ' ', trim($node->text())) ?? '');
        self::assertSame("+500,00\u{00A0}€ (+4,55\u{00A0}%) vs octobre 2026", $variations[0]);
        self::assertSame("+1\u{202F}500,00\u{00A0}€ (+15,00\u{00A0}%) sur 1 an", $variations[1]);
        self::assertSelectorExists('svg.pyramid');
        self::assertSelectorTextContains('.summary-figures', '1 saisi, 0 reporté');
    }

    public function testPlaceAmongFrenchHouseholds(): void
    {
        // Median net worth of households (Insee, early 2024): €148,100.
        $this->givenBalances(['2026-11' => 14_810_000]);

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.wealth-rank-main', "environ 50\u{00A0}%");
        self::assertSelectorTextContains('.wealth-rank', '6e décile');
        self::assertSame('100', $crawler->filter('.wealth-rank-marker')->attr('cx'), 'repère au milieu de l\'échelle');
        self::assertCount(11, $crawler->filter('[aria-labelledby="wealth-rank-title"] .wealth-rank-table tbody tr'));
        self::assertSelectorExists('.wealth-rank a[href="https://www.insee.fr/fr/statistiques/2388851"]');
    }

    public function testRankingsSitSideBySideAndInvestmentsAreComparedWithTheBenchmark(): void
    {
        $this->givenBalances(['2026-11' => 2_000_000]);
        $pea = self::createAccount('PEA', 'pea', '2020-01-01');
        self::service(StatementRepository::class)->save(self::owner(), $pea, Month::fromString('2026-11'), 3_000_000);

        $crawler = $this->client->request('GET', '/');

        // One card per ranking, in the same row; the split has its card, next to the recommendations.
        self::assertCount(2, $crawler->filter('.rank-grid > section.card.wealth-rank'));
        self::assertSame('wealth-rank-title', $crawler->filter('.rank-grid > section')->first()->attr('aria-labelledby'));
        self::assertCount(0, $crawler->filter('.pyramid-card .wealth-rank'));
        self::assertCount(1, $crawler->filter('.dashboard-grid > .allocation-card + .advice-card'));
        $rows = $crawler->filter('.allocation-card .allocation-table tbody tr')->each(
            static fn($row): string => preg_replace('/[ \t\r\n]+/', ' ', trim($row->text())) ?? '',
        );
        // Investments: €30,000 of shares only (the current account belongs to the emergency savings).
        self::assertSame(["Fonds euros 0\u{00A0}% 45\u{00A0}%", "Actions 100\u{00A0}% 25\u{00A0}%", "Immobilier 0\u{00A0}% 25\u{00A0}%", "Exotique 0\u{00A0}% 5\u{00A0}%"], $rows);
        self::assertSelectorExists('.allocation a[href^="https://avenuedesinvestisseurs.fr/"]');
    }

    public function testRecommendationsMeasureThePrecautionSavingsAgainstTheBudget(): void
    {
        // €20,000 on the current account (emergency savings) and €30,000 in shares; €10,000 of spending per month.
        $this->givenBalances(['2026-11' => 2_000_000]);
        $pea = self::createAccount('PEA', 'pea', '2020-01-01');
        self::service(StatementRepository::class)->save(self::owner(), $pea, Month::fromString('2026-11'), 3_000_000);
        self::createBudget(self::owner(), 'Alex', 'Logement', 'Loyer', 1_000_000, '2026-11');

        $crawler = $this->client->request('GET', '/');

        $card = $crawler->filter('.advice-card');
        self::assertCount(1, $card);
        self::assertSelectorExists('.advice-card svg.precaution-gauge.is-low[role="img"]');
        $text = self::plain($card->text());
        self::assertStringContainsString('20 000,00 €, soit 2 mois de dépenses (10 000,00 € par mois, épargne non comprise).', $text);
        self::assertStringContainsString('Attention Il manque 10 000,00 € pour atteindre la cible de 3 mois de dépenses (30 000,00 €).', $text);
        self::assertStringContainsString('Vous détenez 30 000,00 € en actions et placements exotiques', $text);
        self::assertStringContainsString('Actions : 100 % de vos placements, au-dessus du repère de 25 %.', $text);
    }

    public function testRecommendationsInviteToEnterABudget(): void
    {
        $this->givenBalances(['2026-11' => 500_000]);

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorNotExists('.advice-card svg.precaution-gauge');
        self::assertStringContainsString('5 000,00 € d\'épargne de précaution. Saisissez votre budget', self::plain($crawler->filter('.advice-card')->text()));
        self::assertSelectorExists('.advice-card a[href="/budget"]');
        self::assertSelectorTextContains('.advice-card .advice-list .is-ok', 'Rien à signaler');
    }

    public function testRecommendationsAlertWithoutPrecautionSavings(): void
    {
        $pea = self::createAccount('PEA', 'pea', '2020-01-01');
        self::service(StatementRepository::class)->save(self::owner(), $pea, Month::fromString('2026-11'), 3_000_000);

        $crawler = $this->client->request('GET', '/');

        self::assertSelectorTextContains('.advice-card .precaution .advice.is-alert', 'Aucune épargne de précaution en novembre 2026');
        self::assertCount(1, $crawler->filter('.advice-card .advice-list .is-warning'), 'la base d\'abord');
    }

    public function testPlaceAmongAdultsWorldwide(): void
    {
        // $100,000 at the rate of $1.1750: €85,106.38, threshold below which 83.2 % of adults are (UBS, end of 2025).
        $this->givenBalances(['2026-11' => 8_510_638]);

        $crawler = $this->client->request('GET', '/');

        $world = $crawler->filter('[aria-labelledby="world-rank-title"]');
        self::assertCount(1, $world);
        self::assertStringContainsString("environ 83\u{00A0}% des adultes du monde", $world->filter('.wealth-rank-main')->text());
        self::assertSame('163.7', $world->filter('.wealth-rank-marker')->attr('cx'));
        self::assertCount(4, $world->filter('.wealth-rank-table tbody tr'));
        self::assertStringContainsString('1,1750', $world->text());
        self::assertCount(1, $world->filter('a[href^="https://www.ubs.com/"]'));
    }

    public function testTopPercentAndNoPlaceWithoutData(): void
    {
        $this->givenBalances(['2026-11' => 300_000_000]);

        $this->client->request('GET', '/');
        self::assertSelectorTextContains('.wealth-rank-main', "du 1\u{00A0}% des ménages les mieux dotés");
        self::assertSelectorTextContains('#world-rank-title + .wealth-rank-main', "du 1\u{00A0}% des adultes les mieux dotés du monde");

        $this->client->request('GET', '/?mois=2025-06');
        self::assertSelectorNotExists('.wealth-rank');
        self::assertSelectorNotExists('#world-rank-title');
    }

    public function testMonthBeforeTheFirstStatementOffersToJumpToIt(): void
    {
        $this->givenBalances(['2026-03' => 100]);

        $this->client->request('GET', '/?mois=2025-06');

        self::assertSelectorTextContains('.empty-state', 'Vos données commencent en mars 2026.');
        self::assertSelectorExists('.empty-state a[href="/?mois=2026-03"]');
        self::assertSelectorExists('.empty-state a[href="/saisie?mois=2025-06"]');
    }

    /** Bug of 2.13.0: next month, entered in advance, could not be chosen on the wealth page. */
    public function testNextMonthEnteredInAdvanceCanBeShownButNotTheOneAfter(): void
    {
        $this->givenBalances(['2026-11' => 100_000, '2026-12' => 120_000]);

        $crawler = $this->client->request('GET', '/?mois=2026-12');
        self::assertResponseIsSuccessful();
        self::assertSame('1 200,00 €', self::plain($crawler->filter('.summary-total')->text()));
        self::assertSame('/?mois=2026-12', $this->client->request('GET', '/')->filter('.month-nav a[href="/?mois=2026-12"]')->attr('href'));

        $this->client->request('GET', '/?mois=2027-01');
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->request('GET', '/rapport?mois=2026-12');
        self::assertResponseIsSuccessful();
    }

    public function testMonthPickerLinksToEveryPastMonthOfTheYear(): void
    {
        $this->givenBalances(['2026-01' => 100]);
        $crawler = $this->client->request('GET', '/?mois=2026-05');

        self::assertCount(12, $crawler->filter('.mp-months a.mp-cell'), 'January to December, next month');
        self::assertCount(0, $crawler->filter('.mp-months span.is-disabled'));
        self::assertStringContainsString('is-today', (string) $crawler->filter('.mp-months a.mp-cell')->eq(10)->attr('class'), 'November, the current month');
        self::assertSame('/?mois=2026-05', $crawler->filter('.mp-months [aria-current="date"]')->attr('href'));
        self::assertSelectorExists('.mp-foot a[href="/"]');
    }

    private static function plain(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(["\u{202F}", "\u{00A0}"], ' ', $text)) ?? '');
    }

    /** @param array<string, int> $balances month => cents, on a current account */
    private function givenBalances(array $balances): void
    {
        $id = self::createAccount('Compte courant', 'compte_courant', '2020-01-01');
        foreach ($balances as $month => $cents) {
            self::service(StatementRepository::class)->save(self::owner(), $id, Month::fromString($month), $cents);
        }
    }
}
