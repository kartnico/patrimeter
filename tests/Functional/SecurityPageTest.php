<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/**
 * My account page, in tabs (profile, sign-in and security, AI agents, log) and account menu: password, backup codes,
 * other sessions, sign-in log, identity confirmation.
 */
final class SecurityPageTest extends FunctionalTestCase
{
    private const string NEW_PASSWORD = 'une toute nouvelle phrase de passe';

    public function testPageShowsTheAccountInTabs(): void
    {
        $this->logIn();
        $crawler = $this->client->request('GET', '/securite');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mon compte');
        self::assertSelectorTextContains('.profile', 'Adresse de connexion');
        self::assertSelectorTextContains('main', self::EMAIL);
        self::assertSelectorExists('form[action="/securite/mot-de-passe"]');
        $tabs = $crawler->filter('nav.account-tabs a');
        self::assertSame(['/securite', '/securite/connexion', '/comptes', '/biens', '/budget/foyer', '/budget/carbure', '/securite/agents-ia'], $tabs->each(static fn($tab): ?string => $tab->attr('href')));
        self::assertSame(['Profil', 'Connexion et sécurité', 'Comptes', 'Biens immobiliers', 'Foyer fiscal et catégories', 'Carbure', 'Agents IA'], $tabs->each(static fn($tab): string => trim($tab->text())));
        self::assertSame('page', $tabs->eq(0)->attr('aria-current'));
        self::assertCount(1, $crawler->filter('.account-items a[rel="help"][href="https://kartnico.github.io/patrimeter/fr/"]'), 'user guide in the menu');
        self::assertSelectorNotExists('table.journal', 'the sign-in log is in the sign-in and security tab');

        $crawler = $this->client->request('GET', '/securite/connexion');
        self::assertResponseIsSuccessful();
        self::assertSame('page', $crawler->filter('nav.account-tabs a')->eq(1)->attr('aria-current'));
        self::assertSelectorTextContains('main', 'Codes de secours restants : 10');
        self::assertSelectorTextContains('main', 'Passkeys (FIDO2) et clés de sécurité');
        self::assertSelectorNotExists('form[action="/securite/codes-de-secours"]', 'actions sensibles masquées sans confirmation récente');
        self::assertSelectorExists('input[name="_return"][value="/securite/connexion"]', 'retour à cet onglet après la confirmation');
        // Sign-in log: the last section of this tab.
        self::assertSame('journal', $crawler->filter('main section.card')->last()->attr('id'));
        self::assertSelectorTextContains('#journal table.journal tbody tr', '15/11/2026 à 12:00');
        self::assertSelectorTextContains('#journal table.journal tbody tr', 'Connexion');

        // The former tab of the log leads there.
        $this->client->request('GET', '/securite/journal');
        self::assertResponseRedirects('/securite/connexion#journal', Response::HTTP_MOVED_PERMANENTLY);
    }

    public function testTheAccountMenuGroupsProfileAiAgentsDocumentationAndSignOut(): void
    {
        $this->logIn();
        $crawler = $this->client->request('GET', '/securite');

        $menu = $crawler->filter('details.account-menu');
        self::assertCount(1, $menu);
        self::assertSame('Mon compte', $menu->filter('summary .account-label')->text());
        self::assertNotNull($menu->filter('summary')->attr('data-current'), 'Mon compte en cours');
        $links = $menu->filter('.account-items a');
        self::assertSame(['/securite', '/securite/agents-ia', 'https://kartnico.github.io/patrimeter/fr/'], $links->each(static fn($link): ?string => $link->attr('href')));
        self::assertSame(['Mon profil', 'Agents IA', 'Documentation'], $links->each(static fn($link): string => trim($link->text())));
        self::assertSame('page', $links->eq(0)->attr('aria-current'));
        self::assertSame('/deconnexion', $menu->filter('.account-items form')->attr('action'));
        self::assertSame('Déconnexion', trim($menu->filter('.account-items form button')->text()), 'déconnexion en dernier, dans le menu');

        // Accounts are a tab of My account: the menu stays current there, not on the wealth page.
        $this->client->request('GET', '/comptes');
        self::assertSelectorExists('details.account-menu summary[data-current]');
        $this->client->request('GET', '/');
        self::assertSelectorNotExists('details.account-menu summary[data-current]');
    }

    public function testPasswordChangeKeepsThisSessionAndEndsTheOthers(): void
    {
        $this->logIn();
        $otherDevice = $this->saveSession();
        $this->client->getCookieJar()->clear();
        self::clock()->sleep(30);
        $this->logIn();

        $this->changePassword(self::PASSWORD, self::NEW_PASSWORD);
        self::assertResponseRedirects('/securite', Response::HTTP_SEE_OTHER);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailTextBodyContains($email, 'mot de passe de votre compte a été modifié');
        $this->client->followRedirect();
        self::assertStringContainsString('Mot de passe modifié.', $this->flashes());
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful('cette session reste ouverte');

        $this->restoreSession($otherDevice);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER, 'l\'autre session est terminée');

        self::clock()->sleep(60);
        $this->logIn(self::EMAIL, self::NEW_PASSWORD);
    }

    public function testPasswordChangeRequiresTheCurrentPasswordAndAValidNewOne(): void
    {
        $this->logIn();

        $this->changePassword('mauvais mot de passe', self::NEW_PASSWORD);
        $this->client->followRedirect();
        self::assertStringContainsString('Mot de passe actuel incorrect.', $this->flashes());

        self::clock()->sleep(1);
        $this->changePassword(self::PASSWORD, 'trop court');
        $this->client->followRedirect();
        self::assertStringContainsString('au moins 12 caractères', $this->flashes());

        $this->changePassword(self::PASSWORD, self::NEW_PASSWORD, 'une autre confirmation');
        $this->client->followRedirect();
        self::assertStringContainsString('ne correspondent pas', $this->flashes());

        $this->changePassword(self::PASSWORD, 'mon adresse ' . self::EMAIL);
        $this->client->followRedirect();
        self::assertStringContainsString('ne doit pas contenir votre adresse', $this->flashes());

        self::assertSame(1, self::queryInt("SELECT COUNT(*) FROM auth_events WHERE type = 'reauth_failed'"));
    }

    public function testCurrentPasswordCannotBeGuessedWithoutLimit(): void
    {
        $this->logIn();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->changePassword('mauvais mot de passe', self::NEW_PASSWORD);
        }
        $this->changePassword(self::PASSWORD, self::NEW_PASSWORD);
        $this->client->followRedirect();

        self::assertStringContainsString('Trop de tentatives. Réessayez dans 15 minutes.', $this->flashes(), 'même le bon mot de passe attend la fin du blocage');
        self::assertSame(5, self::queryInt("SELECT COUNT(*) FROM auth_events WHERE type = 'reauth_failed'"));
        self::assertSame(0, self::queryInt("SELECT COUNT(*) FROM auth_events WHERE type = 'password_changed'"));
    }

    public function testSensitiveActionsRequireARecentIdentityConfirmation(): void
    {
        $this->logIn();

        $this->submit('/securite/codes-de-secours', [], '/securite');
        self::assertResponseRedirects('/securite/connexion', Response::HTTP_SEE_OTHER);
        $this->submit('/securite/sessions', [], '/securite');
        self::assertResponseRedirects('/securite/connexion', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('confirmez d\'abord votre identité', $this->flashes());

        $this->reauthenticate('mauvais mot de passe');
        $this->client->followRedirect();
        self::assertStringContainsString('Mot de passe incorrect.', $this->flashes());
        self::assertSelectorNotExists('form[action="/securite/codes-de-secours"]');

        self::clock()->sleep(2);
        $this->reauthenticate(self::PASSWORD);
        $this->client->followRedirect();
        self::assertSelectorExists('form[action="/securite/codes-de-secours"]');
        self::assertSelectorExists('form[action="/securite/sessions"]');

        self::clock()->sleep(5 * 60 + 1);
        $this->client->request('GET', '/securite/connexion');
        self::assertSelectorNotExists('form[action="/securite/codes-de-secours"]', 'confirmation valable 5 minutes');
    }

    public function testRegeneratedBackupCodesReplaceTheOldOnes(): void
    {
        $oldCodes = [];
        TestUsers::create(static::getContainer(), 'codes@example.test', backupCodes: $oldCodes);
        $this->logIn('codes@example.test');
        $this->reauthenticate(self::PASSWORD);

        $crawler = $this->client->request('POST', '/securite/codes-de-secours', ['_csrf' => $this->csrfToken('/securite')]);
        self::assertResponseIsSuccessful();
        self::assertEmailCount(1);
        $codes = $crawler->filter('ul.backup-codes code')->each(static fn($node): string => $node->text());
        self::assertCount(10, $codes);
        self::assertSame([], array_intersect($oldCodes, $codes));
        self::assertSelectorTextContains('main', 'Les codes précédents ne fonctionnent plus.');

        $this->submit('/deconnexion');
        $this->submitPassword('codes@example.test');
        $this->submitCode($oldCodes[0]);
        self::assertResponseRedirects('/connexion/code', Response::HTTP_SEE_OTHER, 'ancien code refusé');
        self::clock()->sleep(1);
        $this->submitCode($codes[0]);
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER, 'nouveau code accepté');
    }

    public function testOtherSessionsCanBeEnded(): void
    {
        $this->logIn();
        $otherDevice = $this->saveSession();
        $this->client->getCookieJar()->clear();
        self::clock()->sleep(30);
        $this->logIn();
        $this->reauthenticate(self::PASSWORD);

        $this->submit('/securite/sessions', [], '/securite');
        self::assertResponseRedirects('/securite/connexion#sessions', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Toutes vos autres sessions ont été déconnectées.', $this->flashes());

        $this->restoreSession($otherDevice);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);

        $types = self::service(Connection::class)->fetchFirstColumn('SELECT type FROM auth_events ORDER BY id');
        self::assertSame(['login', 'login', 'reauthenticated', 'sessions_revoked'], $types);
    }

    public function testReauthenticationReturnsOnlyToInternalPages(): void
    {
        $this->logIn();
        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => 'https://ailleurs.example/'], '/securite');

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
    }

    private function changePassword(string $current, string $new, ?string $confirmation = null): void
    {
        $this->submit('/securite/mot-de-passe', [
            'current_password' => $current,
            'new_password' => $new,
            'new_password_confirmation' => $confirmation ?? $new,
        ], '/securite');
    }

    private function reauthenticate(string $password): void
    {
        $this->submit('/securite/confirmer', ['password' => $password, '_return' => '/securite/connexion'], '/securite');
    }
}
