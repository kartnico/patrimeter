<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\FirstAccount;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;

/**
 * Installation wizard: on an instance without any account of its own, the sign-in page leads to it, the configuration
 * is checked and the first account created (address deemed confirmed, TOTP next); then the page no longer exists.
 */
final class SetupTest extends FunctionalTestCase
{
    private const string ADMIN = 'admin@example.test';
    private const string ADMIN_PASSWORD = 'une phrase de passe solide';

    public function testAnInstanceWithAnAccountHasNoWizard(): void
    {
        $this->client->request('GET', '/installation');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->submit('/installation', ['email' => self::ADMIN, 'password' => self::ADMIN_PASSWORD, 'password_confirmation' => self::ADMIN_PASSWORD], '/connexion');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertNull(self::service(UserRepository::class)->findByEmail(self::ADMIN));

        $this->client->request('GET', '/connexion');
        self::assertResponseIsSuccessful();
    }

    public function testANewInstanceIsLedToTheWizardThatChecksItsConfiguration(): void
    {
        self::withoutAccounts();
        $this->client->request('GET', '/connexion');
        self::assertResponseRedirects('/installation');

        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Installation');
        $checks = $crawler->filter('ul[aria-labelledby="setup-checks-title"] li');
        self::assertCount(4, $checks);
        // The test client speaks plain HTTP: the first point says what to do, in words and not only with a mark.
        self::assertStringContainsString('Connexion chiffrée (HTTPS) (à corriger)', $checks->eq(0)->text());
        self::assertStringContainsString('servie sans HTTPS', $checks->eq(0)->text());
        self::assertStringContainsString('(correct)', $checks->eq(1)->text(), 'DEFAULT_URI of the tests: http://localhost');
        self::assertCount(1, $crawler->filter('form[action="/installation"] input[name="_csrf"]'));
    }

    public function testTheFirstAccountIsCreatedSignedInAndSentToItsAuthenticatorApp(): void
    {
        self::withoutAccounts();
        $this->submit('/installation', ['email' => self::ADMIN, 'password' => self::ADMIN_PASSWORD, 'password_confirmation' => self::ADMIN_PASSWORD], '/installation');

        self::assertResponseRedirects('/securite/application', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Protégez votre compte');
        self::assertSelectorTextContains('.onboarding-steps', 'Étape 2 sur 4');
        $account = self::service(UserRepository::class)->findByEmail(self::ADMIN);
        self::assertNotNull($account);
        self::assertTrue($account['email_verified'], 'address deemed confirmed, as with app:user:create');

        self::assertFalse(self::service(FirstAccount::class)->isNeeded(), 'the wizard is gone once the first account exists');
    }

    public function testInvalidEntriesAreExplainedFieldByField(): void
    {
        self::withoutAccounts();
        $this->submit('/installation', ['email' => 'pas une adresse', 'password' => 'court', 'password_confirmation' => 'court'], '/installation');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('#err-email', 'Adresse e-mail invalide.');
        self::assertSelectorExists('#err-password');
        self::assertTrue(self::service(FirstAccount::class)->isNeeded(), 'nothing created');
    }

    public function testDemonstrationAccountsDoNotCountAsTheFirstAccount(): void
    {
        self::withoutAccounts();
        $demo = TestUsers::create(static::getContainer(), 'demo@example.test');
        self::service(Connection::class)->insert('demo_accounts', ['user_id' => $demo->value, 'expires_at' => '2026-11-16T12:00:00+00:00']);

        self::assertTrue(self::service(FirstAccount::class)->isNeeded());
    }

    public function testOnlyTheVeryFirstAccountCanBeCreated(): void
    {
        self::withoutAccounts();
        $firstAccount = self::service(FirstAccount::class);

        self::assertNotNull($firstAccount->create(self::ADMIN, self::ADMIN_PASSWORD));
        // A second visitor, whose page was displayed before the first account existed.
        self::assertNull($firstAccount->create('second@example.test', self::ADMIN_PASSWORD));
        self::assertNull(self::service(UserRepository::class)->findByEmail('second@example.test'));
    }

    /** Fresh instance: the accounts of the test (rolled back afterwards) removed. */
    private static function withoutAccounts(): void
    {
        self::service(Connection::class)->executeStatement('DELETE FROM users');
    }
}
