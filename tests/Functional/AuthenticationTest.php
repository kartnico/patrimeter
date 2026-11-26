<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\User;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

/** Sign-in: email address and password, then TOTP code or backup code. */
final class AuthenticationTest extends FunctionalTestCase
{
    public function testAfterTheFullLoginTheFirstRequestedPageIsReached(): void
    {
        $this->client->request('GET', '/comptes?filtre=1');
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);

        $this->submitPassword();
        self::assertResponseRedirects('/comptes?filtre=1', Response::HTTP_SEE_OTHER, 'le second facteur mémorisera cette page');
        $this->client->followRedirect();
        self::assertResponseRedirects('/connexion/code');
        $this->submitCode(TestUsers::totpCode(static::getContainer()));

        self::assertResponseRedirects('/comptes?filtre=1', Response::HTTP_SEE_OTHER);
    }

    private const string BAD_CREDENTIALS = 'Adresse e-mail ou mot de passe incorrect.';

    public function testAnonymousVisitorIsSentToTheLoginPage(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
    }

    public function testLoginPage(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Connexion');
        self::assertCount(1, $crawler->filter('input[type="email"][name="email"][autocomplete="username"]'));
        self::assertCount(1, $crawler->filter('input[type="password"][name="password"][autocomplete="current-password"]'));
        self::assertCount(1, $crawler->filter('form[action="/connexion"] input[type="hidden"][name="_csrf"]'));
        self::assertCount(1, $crawler->filter('a[href="/inscription"]'), 'lien vers l\'inscription (ouverte par défaut)');
        self::assertSelectorNotExists('.flash-error');
    }

    public function testLoginTakesAPasswordThenACode(): void
    {
        $this->client->request('GET', '/connexion');
        $sessionName = $this->client->getRequest()->getSession()->getName();
        $anonymousSession = $this->client->getCookieJar()->get($sessionName)?->getValue();

        $this->submitPassword();
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertResponseRedirects('/connexion/code');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Code de vérification');
        self::assertCount(1, $crawler->filter('input[name="code"][autocomplete="one-time-code"]'));
        self::assertNotSame($anonymousSession, $this->client->getCookieJar()->get($sessionName)?->getValue(), 'nouvel identifiant de session');

        $this->submitCode(TestUsers::totpCode(static::getContainer()));
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav.nav');
    }

    public function testCodeFromThePreviousPeriodIsStillAccepted(): void
    {
        $this->submitPassword();
        $this->submitCode(TestUsers::totpCode(static::getContainer(), -1));

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
    }

    public function testNothingIsAccessibleBeforeTheCode(): void
    {
        $this->submitPassword();

        foreach (['/', '/comptes', '/saisie', '/securite', '/securite/application'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseRedirects('/connexion/code', null, $path);
        }
        $this->submit('/comptes/nouveau', ['name' => 'Intrus', 'type_id' => '3', 'opened_on' => '2026-01-01'], '/connexion/code');
        self::assertResponseRedirects('/connexion/code');
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts'));
    }

    public function testWrongCodeIsRejectedAndFiveWrongCodesLockTheAccount(): void
    {
        $this->submitPassword();
        $this->submitCode('000000');
        self::assertResponseRedirects('/connexion/code', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Code incorrect.');

        for ($i = 2; $i <= 5; ++$i) {
            $this->submitCode('000000');
        }
        $this->submitCode(TestUsers::totpCode(static::getContainer()));
        self::assertResponseRedirects('/connexion/code', Response::HTTP_SEE_OTHER, 'même le bon code est refusé pendant le blocage');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Trop de tentatives. Réessayez dans 15 minutes.');

        self::assertSame(5, self::queryInt("SELECT COUNT(*) FROM auth_events WHERE type = 'code_failed'"), 'chaque code faux est journalisé');
    }

    public function testATotpCodeCannotBeReplayed(): void
    {
        $code = TestUsers::totpCode(static::getContainer());
        $this->logIn();
        $this->submit('/deconnexion');

        $this->submitPassword();
        $this->submitCode($code);
        self::assertResponseRedirects('/connexion/code', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash-error', 'Ce code vient d\'être utilisé');
    }

    public function testBackupCodeWorksOnceAndIsJournaled(): void
    {
        $codes = [];
        TestUsers::create(static::getContainer(), 'secours@example.test', backupCodes: $codes);

        $this->submitPassword('secours@example.test');
        $this->submitCode(strtolower(str_replace('-', ' ', $codes[0])));
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER, 'casse et séparateurs indifférents');
        $this->submit('/deconnexion');

        $this->submitPassword('secours@example.test');
        $this->submitCode($codes[0]);
        self::assertResponseRedirects('/connexion/code', Response::HTTP_SEE_OTHER, 'un code de secours ne sert qu\'une fois');

        $types = self::service(Connection::class)->fetchFirstColumn('SELECT type FROM auth_events ORDER BY id');
        self::assertContains('backup_code_used', $types);
        self::assertContains('code_failed', $types);
    }

    public function testFewRemainingBackupCodesAreRemindedAfterLogin(): void
    {
        $user = TestUsers::create(static::getContainer(), 'secours@example.test');
        self::service(Connection::class)->executeStatement(
            "UPDATE backup_codes SET used_at = '2026-11-01T00:00:00Z' WHERE user_id = ? AND code_hash IN (SELECT code_hash FROM backup_codes WHERE user_id = ? LIMIT 7)",
            [$user->value, $user->value],
        );

        $this->submitPassword('secours@example.test');
        $this->submitCode(TestUsers::totpCode(static::getContainer()));
        $this->client->followRedirect();

        self::assertStringContainsString('Il vous reste 3 codes de secours', $this->flashes());
    }

    public function testWrongPasswordAndUnknownAddressGetTheSameAnswer(): void
    {
        $this->submitPassword(self::EMAIL, 'faux mot de passe');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSelectorTextSame('.flash-error', self::BAD_CREDENTIALS);
        self::assertInputValueSame('email', self::EMAIL);

        $this->submitPassword('inconnu@example.test', 'faux mot de passe');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSelectorTextSame('.flash-error', self::BAD_CREDENTIALS);

        $this->logIn();
    }

    public function testFifthFailureFromTheSameAddressLocksItForFifteenMinutes(): void
    {
        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->submitPassword("inconnu$attempt@example.test", 'faux mot de passe');
        }
        self::assertSelectorTextContains('.flash-error', self::BAD_CREDENTIALS . ' Réessayez dans 15 minutes.');

        // Even the right password is not checked during the lockout.
        $this->submitPassword();
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $retryAfter = (int) $this->client->getResponse()->headers->get('Retry-After');
        self::assertGreaterThan(840, $retryAfter);
        self::assertLessThanOrEqual(900, $retryAfter);
        self::assertSelectorTextContains('.flash-error', 'Trop de tentatives. Réessayez dans 15 minutes.');
    }

    public function testFailedPasswordIsJournaledForTheAccountOnly(): void
    {
        $this->submitPassword(self::EMAIL, 'faux mot de passe');
        $this->client->getCookieJar()->clear();
        self::clock()->sleep(2);
        $this->submitPassword('inconnu@example.test', 'faux mot de passe');

        $events = self::service(Connection::class)->fetchAllAssociative('SELECT user_id, type, success FROM auth_events');
        self::assertSame([['user_id' => self::owner()->value, 'type' => 'login_failed', 'success' => 0]], $events);
    }

    public function testAddressNotYetConfirmedIsOnlyRevealedWithTheRightPassword(): void
    {
        TestUsers::create(static::getContainer(), 'nouveau@example.test', verified: false, totp: false);

        $this->submitPassword('nouveau@example.test', 'faux mot de passe');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSelectorTextContains('.flash-error', self::BAD_CREDENTIALS);
        self::assertEmailCount(0);

        self::clock()->sleep(2);
        $this->submitPassword('nouveau@example.test');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSelectorTextContains('.flash-error', 'Confirmez d\'abord votre adresse e-mail');
        self::assertEmailCount(1);
        self::assertEmailTextBodyContains(self::getMailerMessage() ?? self::fail('e-mail attendu'), '/inscription/confirmation/');
    }

    public function testOverlongPasswordIsRejectedWithoutBeingHashed(): void
    {
        $this->submitPassword(self::EMAIL, str_repeat('a', 5000));

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testInvalidCsrfTokenSendsBackToTheFormWithoutCountingAnAttempt(): void
    {
        $this->client->request('POST', '/connexion', ['_csrf' => 'invalide', 'email' => self::EMAIL, 'password' => self::PASSWORD]);

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Le formulaire a expiré', $this->flashes());

        $this->logIn();
    }

    public function testAuthenticatedUserIsRedirectedAwayFromTheLoginPage(): void
    {
        $this->logIn();
        $this->client->request('GET', '/connexion');

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
    }

    public function testLogoutRequiresPostAndAValidToken(): void
    {
        $this->logIn();

        $this->client->request('GET', '/deconnexion');
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);

        $this->submit('/deconnexion');
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Vous êtes déconnecté.', $this->flashes());

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
    }

    public function testLogoutIsPossibleBeforeTheCode(): void
    {
        $this->submitPassword();
        $this->submit('/deconnexion', [], '/connexion/code');

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        $this->client->request('GET', '/connexion/code');
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
    }

    public function testSessionExpiresAfterThirtyMinutesOfInactivity(): void
    {
        $this->logIn();
        self::clock()->sleep(29 * 60);
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful('29 minutes : toujours connecté');

        self::clock()->sleep(30 * 60 + 1);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertStringContainsString('Votre session a expiré', $this->flashes());
    }

    public function testSessionEndsTwelveHoursAfterLoginEvenWhenActive(): void
    {
        $this->logIn();
        for ($elapsed = 0; $elapsed <= 12 * 60; $elapsed += 20) {
            self::clock()->sleep(20 * 60);
            $this->client->request('GET', '/');
        }

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
    }

    public function testChangingThePasswordEndsExistingSessions(): void
    {
        $this->logIn();
        $hash = self::service(PasswordHasherFactoryInterface::class)->getPasswordHasher(User::class)->hash('un tout nouveau mot de passe');
        self::service(UserRepository::class)->updatePasswordHash(self::owner(), $hash);

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
    }

    public function testLoginIsJournaled(): void
    {
        $this->logIn();

        $events = self::service(Connection::class)->fetchAllAssociative('SELECT type, success, ip FROM auth_events');
        self::assertSame([['type' => 'login', 'success' => 1, 'ip' => '127.0.0.1']], $events);
    }
}
