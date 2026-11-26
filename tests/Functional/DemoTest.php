<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Demo\DemoAccounts;
use Patrimeter\Domain\UserId;
use Patrimeter\Http\DemoTour;
use Patrimeter\Infrastructure\Persistence\DemoAccountRepository;
use Patrimeter\Security\DemoGuard;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\RouteCatalog;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Demonstration mode (DEMO_MODE, off by default, enabled by .env.test): a temporary account with example data, opened from the sign-in
 * page without password nor TOTP, restricted to the wealth and budget screens, limited in data, deleted at sign-out or
 * at the end of its lifetime.
 */
final class DemoTest extends FunctionalTestCase
{
    public function testTheSignInPageOffersTheDemo(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        self::assertCount(1, $crawler->filter('a[rel="help"][href="https://kartnico.github.io/patrimeter/fr/"]'), 'user guide');

        self::assertCount(1, $crawler->filter('.demo-offer form[action="/demo"] button'));
        self::assertStringContainsString('120 minutes', $crawler->filter('.demo-offer')->text());
    }

    public function testTheDemoOpensATemporaryAccountFilledWithExampleData(): void
    {
        $usersBefore = self::queryInt('SELECT COUNT(*) FROM users');
        $this->startDemo();
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful('straight to the dashboard: neither TOTP nor recovery key');
        self::assertStringContainsString('Résidence principale', $this->body());
        // Clock frozen at 12:00 (Paris): two hours of lifetime.
        self::assertStringContainsString('compte supprimé à 14:00', $crawler->filter('.demo-banner')->text());

        $demo = $this->demoUser();
        self::assertSame($usersBefore + 1, self::queryInt('SELECT COUNT(*) FROM users'));
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM users WHERE id = ? AND (password_hash IS NOT NULL OR totp_secret IS NOT NULL)', [$demo->value]));
        self::assertStringEndsWith('@demo.invalid', (string) self::queryString('SELECT email FROM users WHERE id = ?', [$demo->value]));
        self::assertSame(11, self::queryInt('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [$demo->value]));
        self::assertGreaterThan(300, self::queryInt('SELECT COUNT(*) FROM statements WHERE user_id = ?', [$demo->value]));

        $this->client->request('GET', '/budget');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Camille', $this->body());
        $this->client->request('GET', '/connexion');
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER, 'already signed in');
    }

    public function testTheExampleDataFollowTheLanguage(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'en-GB,en;q=0.9');
        $this->startDemo();
        $this->client->followRedirect();

        self::assertStringContainsString('Main residence', $this->body());
        self::assertStringContainsString('Fictitious data, account deleted at 14:00', $this->body());
    }

    public function testADemoAccountOnlyReachesTheWealthAndBudgetScreens(): void
    {
        $this->startDemo();
        $before = $this->databaseState();
        $checked = 0;
        foreach (RouteCatalog::routes(self::service(RouterInterface::class)) as $name => $route) {
            // The passkey sign-in is an authentication attempt handled by the firewall, before DemoGuard: without a
            // credential, it fails and changes nothing.
            if (in_array($name, [...DemoGuard::ALLOWED_ROUTES, 'app_passkey_login', ...RouteCatalog::SECOND_FACTOR, ...RouteCatalog::BEARER, ...RouteCatalog::OAUTH_ENDPOINTS, ...RouteCatalog::SERVICE_ENDPOINTS], true)) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $parameters = $method === 'POST' ? ['_csrf' => $this->csrfToken()] : [];
                $this->client->request($method, RouteCatalog::path($route), $parameters + ['email' => 'demo@example.test', 'password' => 'x', 'name' => 'Intrus', 'mois' => '2026-11']);
                self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER, "$method {$route->getPath()} ($name)");
                ++$checked;
            }
        }

        self::assertGreaterThanOrEqual(40, $checked);
        self::assertSame($before, $this->databaseState(), 'nothing refused has changed the database');
        $this->client->request('GET', '/securite');
        $this->client->followRedirect();
        self::assertStringContainsString('Cette fonction n\'est pas disponible dans la démonstration.', $this->flashes());
        self::assertResponseIsSuccessful('still signed in');
    }

    public function testADemoAccountCanChangeItsDataUpToItsQuota(): void
    {
        $this->startDemo();
        $demo = $this->demoUser();
        $this->submit('/comptes/nouveau', ['name' => 'Livret jeune', 'type_id' => '3', 'opened_on' => '2026-01-01']);
        self::assertSame(12, self::queryInt('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [$demo->value]), 'the demonstration is usable');

        // Quota reached (stored rows, here unreadable filler rows): nothing more is written, deleting remains possible.
        $token = $this->csrfToken('/comptes');
        $rows = self::service(DemoAccounts::class)->maxRows;
        self::connection()->executeStatement(
            "WITH RECURSIVE n(i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM n WHERE i < CAST(? AS INTEGER)) INSERT INTO budget_months (user_id, month, ciphertext) SELECT ?, printf('%04d-01', 1000 + i), 'x' FROM n",
            [$rows, $demo->value],
        );
        $this->client->request('POST', '/comptes/nouveau', ['_csrf' => $token, 'name' => 'Un de trop', 'type_id' => '3', 'opened_on' => '2026-01-01'], server: ['HTTP_REFERER' => 'http://localhost/comptes']);
        self::assertResponseRedirects('/comptes', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('La démonstration est limitée à', $this->flashes());
        self::assertSame(12, self::queryInt('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [$demo->value]));
    }

    public function testADemoAccountFindsAndAddsItsAccountsAndProperties(): void
    {
        $this->startDemo();
        $demo = $this->demoUser();
        $crawler = $this->client->followRedirect();
        $menu = $crawler->filter('.account-items');
        self::assertCount(1, $menu->filter('a[href="/comptes"]'), 'accounts in the menu');
        self::assertCount(1, $menu->filter('a[href="/biens"]'));
        self::assertCount(1, $menu->filter('a[href="/budget/foyer"]'), 'shortcut to the household and categories');
        self::assertCount(1, $crawler->filter('nav.nav a[href="/objectifs"]'), 'goals in the main navigation');
        self::assertCount(0, $menu->filter('a[href="/securite"]'), 'not the profile, refused to a demo');

        $tabs = $this->client->request('GET', '/comptes')->filter('nav.account-tabs a');
        self::assertSame(['/comptes', '/biens', '/budget/foyer'], $tabs->each(static fn($tab): ?string => $tab->attr('href')));
        self::assertSelectorExists('a[href="/comptes/nouveau"]');

        $type = (string) self::queryInt("SELECT id FROM account_types WHERE code = 'lmnp'");
        $this->submit('/biens/nouveau', ['name' => 'Studio', 'type_id' => $type, 'opened_on' => '2025-01-01', 'value' => '120000', 'share' => '100', 'loans' => [['name' => 'Prêt du studio', 'amount' => '80000', 'mode' => 'prorata']]], '/biens/nouveau');
        self::assertResponseRedirects(null, Response::HTTP_SEE_OTHER);
        self::assertSame(13, self::queryInt('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [$demo->value]), 'the property and its loan');
    }

    public function testAnOversizedFormIsRefused(): void
    {
        $this->startDemo();
        $demo = $this->demoUser();
        $this->submit('/comptes/nouveau', ['name' => str_repeat('a', DemoGuard::MAX_BODY_BYTES), 'type_id' => '3', 'opened_on' => '2026-01-01']);
        $this->client->followRedirect();

        self::assertStringContainsString('Ce formulaire est trop volumineux pour la démonstration.', $this->flashes());
        self::assertSame(11, self::queryInt('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [$demo->value]));
    }

    public function testWritesInBurstsAreSlowedDown(): void
    {
        $this->startDemo();
        $token = $this->csrfToken('/saisie');
        // 60 writes in a row, then one every 5 seconds.
        $start = (int) hrtime(true);
        for ($i = 0; $i < 60; ++$i) {
            $this->client->request('POST', '/saisie', ['_csrf' => $token, 'mois' => '2026-11']);
            self::assertResponseRedirects('/?mois=2026-11', Response::HTTP_SEE_OTHER, "write $i saved");
        }
        // The limiter runs on the real clock, not the frozen one: on a slow runner the burst lasts more than
        // 5 seconds and earns new writes (pipeline 887). They are allowed, then the next write is refused.
        $earned = intdiv((int) hrtime(true) - $start, 5_000_000_000) + 1;
        for ($i = 0; $i <= $earned; ++$i) {
            $this->client->request('POST', '/saisie', ['_csrf' => $token, 'mois' => '2026-11'], server: ['HTTP_REFERER' => 'http://localhost/saisie']);
            if ('/saisie' === $this->client->getResponse()->headers->get('Location')) {
                break;
            }
        }
        self::assertResponseRedirects('/saisie', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Trop d\'enregistrements à la suite dans la démonstration', $this->flashes());
    }

    public function testAnUnknownAddressStaysUnknown(): void
    {
        $this->startDemo();
        $this->client->request('GET', '/inexistante');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testASignedInUserIsNotGivenADemoAccount(): void
    {
        $this->logIn();
        $this->submit('/demo');

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM demo_accounts'));
    }

    public function testTheGuidedTourStartsWithTheDemoAndGoesThroughEveryStep(): void
    {
        $this->startDemo();
        $steps = DemoTour::steps();
        foreach ($steps as $index => $step) {
            $path = self::service(RouterInterface::class)->generate($step['route']);
            self::assertResponseRedirects($path, Response::HTTP_SEE_OTHER, "step $index");
            $crawler = $this->client->followRedirect();
            $card = $crawler->filter('.tour-card');
            self::assertCount(1, $card, "card of step $index");
            self::assertStringContainsString(sprintf('étape %d sur %d', $index + 1, count($steps)), $card->text());
            if ($step['target'] !== null) {
                self::assertSame($step['target'], $card->attr('data-tour-target'));
                self::assertGreaterThan(0, $crawler->filter($step['target'])->count(), "element of step $index on $path");
            }
            $last = $index === count($steps) - 1;
            // "Previous" always first, disabled at the first step: "Next" never moves.
            self::assertSame(['previous', $last ? 'stop' : 'next'], array_slice($card->filter('.tour-actions button')->each(static fn($button): ?string => $button->attr('value')), 0, 2));
            self::assertSame($index === 0, $card->filter('button[value="previous"]')->attr('disabled') !== null);
            $this->client->submit($card->selectButton($last ? 'Terminer la visite' : 'Suivant')->form());
        }

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertSelectorNotExists('.tour-card');
        self::assertSelectorTextContains('.demo-banner button', 'Lancer la visite guidée');
    }

    public function testTheGuidedTourCanBeStoppedResumedAndStartedAgain(): void
    {
        $this->startDemo();
        $this->client->followRedirect();
        $this->client->submit($this->client->getCrawler()->selectButton('Suivant')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.tour-card', 'La pyramide');

        // Another page: the card stays on its step's page, the banner offers to resume.
        $this->client->request('GET', '/budget');
        self::assertSelectorNotExists('.tour-card');
        self::assertSelectorTextContains('.demo-banner button', 'Reprendre la visite guidée (étape 2 sur 13)');
        $this->client->submit($this->client->getCrawler()->selectButton('Reprendre la visite guidée (étape 2 sur 13)')->form());
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.tour-card', 'La pyramide');

        $this->client->submit($this->client->getCrawler()->selectButton('Arrêter la visite')->form());
        $this->client->followRedirect();
        self::assertSelectorNotExists('.tour-card');
        $this->client->submit($this->client->getCrawler()->selectButton('Lancer la visite guidée')->form());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.tour-card', 'étape 1 sur 13');
    }

    public function testOnlyADemoAccountHasAGuidedTour(): void
    {
        $this->logIn();
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('.tour-card');

        $this->submit('/demo/visite', ['action' => 'start']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnUnknownTourActionIsRefused(): void
    {
        $this->startDemo();
        $this->submit('/demo/visite', ['action' => 'ailleurs']);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testSigningOutDeletesTheDemoAccountAndItsData(): void
    {
        $usersBefore = self::queryInt('SELECT COUNT(*) FROM users');
        $this->startDemo();
        $demo = $this->demoUser();

        $this->submit('/deconnexion');

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        self::assertSame($usersBefore, self::queryInt('SELECT COUNT(*) FROM users'));
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts WHERE user_id = ?', [$demo->value]));
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM demo_accounts'));
    }

    public function testAnExpiredDemoAccountIsSignedOutAndDeleted(): void
    {
        $this->startDemo();
        $demo = $this->demoUser();
        self::connection()->executeStatement('UPDATE demo_accounts SET expires_at = ? WHERE user_id = ?', ['2026-11-15T10:59:59Z', $demo->value]);

        $this->client->request('GET', '/budget');

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('La démonstration est terminée', $this->flashes());
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM users WHERE id = ?', [$demo->value]));
    }

    public function testTheScheduledTasksDeleteOnlyTheExpiredDemoAccounts(): void
    {
        $repository = self::service(DemoAccountRepository::class);
        $expired = UserId::fromString(Uuid::v7()->toRfc4122());
        $active = UserId::fromString(Uuid::v7()->toRfc4122());
        $repository->create($expired, 'demo-expired@demo.invalid', '2026-11-15T08:00:00Z', '2026-11-15T10:00:00Z');
        $repository->create($active, 'demo-active@demo.invalid', '2026-11-15T10:30:00Z', '2026-11-15T12:30:00Z');
        $users = self::queryInt('SELECT COUNT(*) FROM users');

        self::assertSame(1, self::service(DemoAccounts::class)->purgeExpired());

        self::assertSame($users - 1, self::queryInt('SELECT COUNT(*) FROM users'));
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM users WHERE id = ?', [$expired->value]));
        self::assertSame(1, $repository->activeCount('2026-11-15T11:00:00Z'));
        $repository->delete(self::owner());
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM users WHERE id = ?', [self::owner()->value]), 'never an ordinary account');
    }

    public function testTheNumberOfDemoAccountsIsLimited(): void
    {
        $repository = self::service(DemoAccountRepository::class);
        for ($i = 0; $i < 50; ++$i) {
            $repository->create(UserId::fromString(Uuid::v7()->toRfc4122()), "demo-$i@demo.invalid", '2026-11-15T10:30:00Z', '2026-11-15T12:30:00Z');
        }
        $users = self::queryInt('SELECT COUNT(*) FROM users');

        $this->startDemo();

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('La démonstration est complète pour le moment. Réessayez dans 90 minutes.', $this->flashes());
        self::assertSame($users, self::queryInt('SELECT COUNT(*) FROM users'));
    }

    public function testTheCreationsPerAddressAreLimited(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->startDemo();
            self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
            $this->client->getCookieJar()->clear();
        }

        $this->startDemo();

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Trop de comptes de démonstration ont été créés. Réessayez dans', $this->flashes());
    }

    public function testAnOrdinaryAccountIsNotRestricted(): void
    {
        $this->logIn();
        $this->client->request('GET', '/securite');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.demo-banner');
    }

    public function testTheDemoShowsStarsAlreadyEarned(): void
    {
        $this->startDemo();
        $crawler = $this->client->request('GET', '/defis');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.nav a[href="/defis"]');
        self::assertMatchesRegularExpression('/[1-9]\d* étoiles?/', $crawler->filter('.star-total')->text(), 'three years of example data earn stars');
        self::assertGreaterThan(0, $crawler->filter('.challenge-list')->first()->filter('.challenge')->count(), 'and leave challenges to take up');
    }

    private function startDemo(): void
    {
        $this->submit('/demo', [], '/connexion');
    }

    private function demoUser(): UserId
    {
        $id = self::queryString('SELECT user_id FROM demo_accounts ORDER BY expires_at DESC LIMIT 1');
        self::assertIsString($id);

        return UserId::fromString($id);
    }

    /** @param list<mixed> $parameters */
    private static function queryString(string $sql, array $parameters = []): ?string
    {
        $value = self::connection()->fetchOne($sql, $parameters);

        return is_string($value) ? $value : null;
    }

    private static function connection(): Connection
    {
        return self::service(Connection::class);
    }

    /** @return array<string, int> number of rows of the tables a demonstration account could change */
    private function databaseState(): array
    {
        $state = [];
        foreach (['accounts', 'statements', 'ownership_shares', 'budget_lines', 'budget_categories', 'users', 'family_members', 'carbure_links', 'api_tokens', 'webauthn_credentials', 'key_envelopes', 'one_time_tokens'] as $table) {
            $state[$table] = self::queryInt("SELECT COUNT(*) FROM $table");
        }

        return $state;
    }
}
