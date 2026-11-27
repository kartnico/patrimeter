<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\GameRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Response;

/**
 * Challenges and stars: the Défis page (level, saver profile, challenges), the stars announced after the monthly entry
 * and never lost, the saver style, the game turned off and on, the first-steps card. Clock frozen in November 2026.
 */
final class ChallengesTest extends FunctionalTestCase
{
    private int $livret;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->livret = self::createAccount('Livret A', 'livret_a', '2026-01-01');
        $statements = self::service(StatementRepository::class);
        foreach (['2026-09', '2026-10'] as $month) {
            $statements->save(self::owner(), $this->livret, Month::fromString($month), 500_000);
        }
        $this->logIn();
    }

    public function testStarsAreAnnouncedAfterTheEntryAndNeverLost(): void
    {
        $this->submit('/saisie', ['mois' => '2026-11', 'amounts' => [$this->livret => '5 100']], '/saisie');
        self::assertResponseRedirects('/?mois=2026-11', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Nouvelle étoile, défi « Série de saisies » : 1 sur 3.', $this->flashes());

        $crawler = $this->client->request('GET', '/defis');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.nav a[aria-current="page"][href="/defis"]');
        $streak = $crawler->filter('.challenge')->reduce(static fn($card): bool => str_contains($card->filter('h3')->text(), 'Série de saisies'));
        self::assertSame('1 étoile acquise sur 3', $streak->filter('.medal-score')->text());
        self::assertStringContainsString('Mois de suite : 3', $streak->filter('.fact')->text());
        // The rail of its thresholds: the first one earned, the next one flagged; short name for the eye, full sentence
        // and state for screen readers.
        $steps = $streak->filter('.rail li');
        self::assertSame(['earned', 'next', 'later'], $steps->each(static fn($step): string => (string) $step->attr('class')));
        self::assertSame('6 mois', $steps->eq(1)->filter('b')->text());
        self::assertSame('6 mois de suite saisis', $steps->eq(1)->filter('.visually-hidden')->text());
        self::assertSame('Suivante', $steps->eq(1)->filter('small')->text());
        // What is missing for the next star, from this month's streak.
        self::assertSame('Pour la 2ᵉ étoile', $streak->filter('.delta b')->text());
        self::assertSame('Encore 3 mois de saisie de suite.', $streak->filter('.delta p')->text());
        // The stars by family add up to the total; the six levels, folded, with the stars each needs.
        $families = $crawler->filter('.score-panel .bar-item');
        self::assertSame(['Premiers pas', 'Saisie des soldes', 'Budget et objectifs', 'Coussin et crédit', 'Sécurité du compte'], $families->each(static fn($family): string => $family->filter('span')->text()));
        self::assertSame('sur 28 étoiles', $crawler->filter('.dial p > span')->text());
        self::assertCount(6, $crawler->filter('.game-level details .level-ladder li'));
        self::assertStringContainsString('sur 28', $crawler->filter('.star-total')->text());
        self::assertStringContainsString('Repères indicatifs, à adapter à votre situation : ce n\'est pas un conseil en investissement.', $crawler->filter('.notice')->text());

        // November deleted: the streak falls to two months, the star stays.
        self::service(StatementRepository::class)->delete(self::owner(), $this->livret, Month::fromString('2026-11'));
        $crawler = $this->client->request('GET', '/defis');
        $streak = $crawler->filter('.challenge')->reduce(static fn($card): bool => str_contains($card->filter('h3')->text(), 'Série de saisies'));
        self::assertSame('1 étoile acquise sur 3', $streak->filter('.medal-score')->text());
        self::assertStringContainsString('Mois de suite : 2', $streak->text());
        self::assertSame('Encore 4 mois de saisie de suite.', $streak->filter('.delta p')->text(), 'still the second star, from two months');
        self::assertSame(1, self::queryInt("SELECT stars FROM game_stars WHERE user_id = ? AND challenge = 'streak'", [self::owner()->value]));
    }

    public function testPendingStarSurvivesReadingChallengesAndSavingWithTheGameOff(): void
    {
        $statements = self::service(StatementRepository::class);
        $august = Month::fromString('2026-08');
        $statements->save(self::owner(), $this->livret, $august, 500_000);
        $this->client->request('GET', '/defis');
        self::assertResponseIsSuccessful();
        // The streak no longer qualifies, but the pending award belongs to the owner, not the page.
        $statements->delete(self::owner(), $this->livret, $august);
        $this->submit('/defis/reglages', ['action' => 'disable'], '/defis');
        $entry = ['mois' => '2026-10', 'amounts' => [$this->livret => '5 100']];
        $this->submit('/saisie', $entry, '/saisie');
        self::assertResponseRedirects('/?mois=2026-10', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('enregistrée', $this->flashes());
        self::assertStringNotContainsString('Nouvelle étoile', $this->flashes());
        self::assertSelectorNotExists('.first-steps');
        self::assertSelectorNotExists('.summary-level');

        $this->submit('/defis/reglages', ['action' => 'enable'], '/defis');
        $this->submit('/saisie', $entry, '/saisie');
        $this->client->followRedirect();
        self::assertStringContainsString('Nouvelle étoile, défi « Série de saisies » : 1 sur 3.', $this->flashes());
        $this->client->request('GET', '/defis');
        $this->submit('/saisie', $entry, '/saisie');
        $this->client->followRedirect();
        self::assertStringNotContainsString('Nouvelle étoile', $this->flashes());
        self::assertSame(1, self::service(GameRepository::class)->stars(self::owner())['streak']);
    }

    public function testTheProfileShowsTheStageAndTheLevel(): void
    {
        $crawler = $this->client->request('GET', '/defis');

        self::assertSame('Découverte', $crawler->filter('.stage-ladder li.is-current strong')->text(), 'two months entered');
        self::assertCount(5, $crawler->filter('.stage-ladder li'));
        // The two statuses at the top, each a link to its card.
        self::assertSame(['#game-level-title', '#game-profile-title'], $crawler->filter('.status-pair a')->each(static fn($link): string => (string) $link->attr('href')));
        self::assertStringContainsString('Stade 1 sur 5', $crawler->filter('.status-pair')->text());
        self::assertStringContainsString('Mon niveau · ', $crawler->filter('#game-level-title')->text());
        self::assertMatchesRegularExpression('/^Encore \d+ étoiles? pour passer au niveau /', $crawler->filter('.game-level .takeaway')->text());
        // The next stage, with how far it is.
        self::assertSame('Mon profil · Découverte', $crawler->filter('#game-profile-title')->text());
        self::assertStringContainsString('Prochain stade : Organisé', $crawler->filter('.game-profile .route')->text());
        self::assertStringContainsString('Mois où tous les comptes sont saisis', $crawler->filter('.game-profile .bar-item')->text());
        self::assertStringContainsString('Renseignez votre foyer et votre budget pour mesurer ce coussin.', $crawler->filter('.challenge-list')->text());
        self::assertStringContainsString('Avant de commencer', $crawler->filter('.challenge-list')->text());
        self::assertStringContainsString('Fixez un objectif d\'épargne pour relever ce défi.', $crawler->filter('.challenge-list')->text());
    }

    public function testTheStyleChangesTheOrderOfTheChallenges(): void
    {
        // Chosen in My profile (Défis section), back there afterwards.
        $crawler = $this->client->request('GET', '/securite');
        self::assertCount(5, $crawler->filter('#game-title ~ form.style-form input[name="style"]'), 'four styles and none');
        $this->submit('/defis/style', ['style' => 'project', '_return' => '/securite#game-title'], '/securite');
        self::assertResponseRedirects('/securite#game-title', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Style d\'épargnant enregistré : Projet.', $this->flashes());
        self::assertSame('project', $crawler->filter('input[name="style"][checked]')->attr('value'));
        // "Premiers pas" first while it is not complete, then the goal first for a project saver.
        $crawler = $this->client->request('GET', '/defis');
        self::assertSelectorTextContains('.game-profile', 'Style : Projet');
        self::assertSame(['Premiers pas', 'Objectif en route'], array_slice($crawler->filter('.challenge h3')->each(static fn($title): string => $title->text()), 0, 2));

        $this->submit('/defis/style', ['style' => 'jackpot'], '/defis');
        $this->client->followRedirect();
        self::assertStringContainsString('Style inconnu.', $this->flashes());
        $this->submit('/defis/style', ['style' => ''], '/defis');
        self::assertNull(self::service(Connection::class)->fetchOne('SELECT saver_style FROM game_settings WHERE user_id = ?', [self::owner()->value]));
    }

    public function testTheGameCanBeTurnedOffAndOnAgain(): void
    {
        $this->submit('/defis/reglages', ['action' => 'disable', '_return' => '/defis'], '/defis');
        self::assertResponseRedirects('/defis', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('vos étoiles sont gardées', $this->flashes());
        self::assertSelectorExists('button[value="enable"]');
        self::assertCount(0, $crawler->filter('nav.nav a[href="/defis"]'), 'out of the navigation');
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.first-steps');
        $crawler = $this->client->request('GET', '/securite');
        self::assertCount(1, $crawler->filter('#game-title ~ form button[value="enable"]'), 'turned on again from the profile');

        $this->submit('/defis/reglages', ['action' => 'enable', '_return' => 'https://ailleurs.example/'], '/securite');
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER, 'never another site');
        $this->client->followRedirect();
        self::assertSelectorExists('nav.nav a[href="/defis"]');
    }

    public function testTheFirstStepsCardCanBeHidden(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertSame('1 étoile sur 5', $crawler->filter('.first-steps .stars .visually-hidden')->text(), 'accounts created, November not entered yet');
        self::assertSelectorExists('.first-steps li:not(.is-done) a[href="/saisie"]', 'the next step');
        self::assertSelectorExists('.first-steps a[href="/defis"]');
        self::assertSelectorExists('.first-steps a[href="/securite#game-title"]', 'the saver style, asked while none is chosen');

        $this->submit('/defis/reglages', ['action' => 'hide_first_steps', '_return' => '/'], '/');
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertSelectorNotExists('.first-steps');
        $this->client->request('GET', '/defis');
        self::assertSelectorExists('button[value="show_first_steps"]', 'shown again from the Défis page');
    }

    public function testTheSavingsRateChallengeFollowsTheRateTheUserChose(): void
    {
        $crawler = $this->client->request('GET', '/defis');
        $card = static fn(Crawler $page): Crawler => $page->filter('.challenge')->reduce(static fn(Crawler $item): bool => str_contains($item->filter('h3')->text(), 'Taux d\'épargne tenu'));
        self::assertStringContainsString('Choisissez le taux d\'épargne que vous visez', $card($crawler)->text(), 'no default rate');
        self::assertCount(0, $card($crawler)->filter('#savings-rate option[selected]'), 'none chosen');

        // Budget: €2,000 of incomes, €400 of savings planned (20 %) from October.
        $budgets = self::service(BudgetRepository::class);
        $budgets->saveHousehold(self::owner(), new Household([1 => 'Alex']));
        $category = $budgets->createCategory(self::owner(), 'Épargne', BudgetGroup::Savings, null);
        $line = $budgets->createLine(self::owner(), $category, 'Livret');
        $budgets->saveBudget(self::owner(), new MonthlyBudget(Month::fromString('2026-10'), [1 => 200_000], [$line => 40_000]));

        $this->submit('/defis/reglages', ['action' => 'savings_rate', 'taux' => '15', '_return' => '/defis'], '/defis');
        self::assertResponseRedirects('/defis', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Taux d\'épargne visé : 15 %.', $this->flashes());
        self::assertSame('15', $card($crawler)->filter('#savings-rate option[selected]')->attr('value'));
        self::assertStringContainsString('Mois de suite à 15 % ou plus : 2', $card($crawler)->text(), 'October, and November carried over');
        self::assertSame('1 étoile acquise sur 3', $card($crawler)->filter('.medal-score')->text());

        // A rate that is not offered is not kept.
        $this->submit('/defis/reglages', ['action' => 'savings_rate', 'taux' => '12', '_return' => '/defis'], '/defis');
        $this->client->followRedirect();
        self::assertStringContainsString('Taux d\'épargne visé retiré.', $this->flashes());
        self::assertNull(self::service(GameRepository::class)->settings(self::owner())['savings_rate_target']);
    }

    public function testTheDashboardMeasuresTheFirstStepsOnly(): void
    {
        // August, September, October entered: the streak would earn its first star if the dashboard measured it.
        self::service(StatementRepository::class)->save(self::owner(), $this->livret, Month::fromString('2026-08'), 500_000);
        $stars = 'SELECT COUNT(*) FROM game_stars WHERE user_id = ? AND challenge = ?';

        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.first-steps');
        self::assertSame(1, self::queryInt('SELECT stars FROM game_stars WHERE user_id = ? AND challenge = ?', [self::owner()->value, 'first_steps']), 'accounts created');
        self::assertSame(0, self::queryInt($stars, [self::owner()->value, 'streak']), 'the other challenges are left to the Défis page');

        $this->client->request('GET', '/defis');
        self::assertSame(1, self::queryInt($stars, [self::owner()->value, 'streak']));
    }

    public function testTheNetWorthCardShowsTheLevelWithALinkToTheChallenges(): void
    {
        self::service(\Doctrine\DBAL\Connection::class)->executeStatement(
            "INSERT INTO game_stars (user_id, challenge, stars, earned_on) VALUES (?, 'streak', 3, '2026-11-01'), (?, 'budget', 2, '2026-11-01')",
            [self::owner()->value, self::owner()->value],
        );

        $crawler = $this->client->request('GET', '/');

        $level = $crawler->filter('.summary .summary-level a');
        self::assertSame('/defis', $level->attr('href'));
        self::assertStringContainsString('Niveau 2 : Attentif', $level->text());
        self::assertStringContainsString('6 étoiles', $level->text(), 'five kept, plus the first step of the account created');

        self::service(\Patrimeter\Application\Game\Game::class)->setEnabled(self::owner(), false);
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.summary-level', 'challenges off');
    }

    public function testTheFirstStepsCardIsGoneOnceTheSixStarsAreKept(): void
    {
        self::service(Connection::class)->executeStatement(
            "INSERT INTO game_stars (user_id, challenge, stars, earned_on) VALUES (?, 'first_steps', 6, '2026-11-01')",
            [self::owner()->value],
        );

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.first-steps');
    }
}
