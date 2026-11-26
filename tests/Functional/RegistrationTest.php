<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/**
 * Registration: form, confirmation link by email (password required), mandatory TOTP setup, without ever revealing
 * whether an address is already registered.
 */
final class RegistrationTest extends FunctionalTestCase
{
    private const string NEW_EMAIL = 'nouvelle@example.test';
    private const string NEW_PASSWORD = 'une phrase de passe solide';

    public function testRegistrationFormIsLinkedFromTheLoginPage(): void
    {
        $crawler = $this->client->request('GET', '/inscription');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Créer un compte');
        self::assertCount(1, $crawler->filter('form[action="/inscription"] input[name="_csrf"]'));
        self::assertCount(2, $crawler->filter('input[type="password"][autocomplete="new-password"]'));
    }

    public function testCompleteRegistrationUpToTheDashboard(): void
    {
        $this->register(self::NEW_EMAIL, self::NEW_PASSWORD);
        self::assertResponseRedirects('/inscription/envoyee', Response::HTTP_SEE_OTHER);
        $link = $this->confirmationLink(self::NEW_EMAIL);
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Vérifiez votre messagerie');
        self::assertSelectorTextContains('main p', 'Un e-mail vient d\'être envoyé à ' . self::NEW_EMAIL . '.');
        self::assertSelectorTextContains('.sent-cases', 'Nouvelle inscription');
        self::assertSelectorTextContains('.sent-cases', 'Adresse qui a déjà un compte');

        self::assertStringStartsWith('http://localhost/inscription/confirmation/', $link, 'lien construit depuis DEFAULT_URI');
        $this->client->request('GET', $link);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Confirmer votre adresse');

        $this->submit($link, ['password' => self::NEW_PASSWORD], $link);
        self::assertResponseRedirects('/securite/application', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Application d\'authentification');
        self::assertCount(1, $crawler->filter('svg.qr-code path.qr-code-modules'));

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/securite/application', Response::HTTP_SEE_OTHER, 'TOTP obligatoire avant tout accès');

        $events = self::service(Connection::class)->fetchFirstColumn('SELECT type FROM auth_events ORDER BY id');
        self::assertSame(['email_verified', 'login'], $events);
    }

    public function testConfirmationRequiresThePasswordChosenAtRegistration(): void
    {
        $this->register(self::NEW_EMAIL, self::NEW_PASSWORD);
        $link = $this->confirmationLink(self::NEW_EMAIL);

        $this->submit($link, ['password' => 'le mot de passe d\'un autre'], $link);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSelectorTextContains('.flash-error', 'Mot de passe incorrect.');
        self::assertFalse(self::service(UserRepository::class)->findByEmail(self::NEW_EMAIL)['email_verified'] ?? true);

        self::clock()->sleep(2);
        $this->submit($link, ['password' => self::NEW_PASSWORD], $link);
        self::assertResponseRedirects('/securite/application', Response::HTTP_SEE_OTHER);
    }

    public function testConfirmationLinkWorksOnceAndExpiresAfter24Hours(): void
    {
        $this->register(self::NEW_EMAIL, self::NEW_PASSWORD);
        $link = $this->confirmationLink(self::NEW_EMAIL);
        self::clock()->sleep(24 * 3600 + 1);

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains('h1', 'Lien invalide ou expiré');
    }

    public function testConfirmationLinkCannotBeUsedTwice(): void
    {
        $this->register(self::NEW_EMAIL, self::NEW_PASSWORD);
        $link = $this->confirmationLink(self::NEW_EMAIL);
        $this->submit($link, ['password' => self::NEW_PASSWORD], $link);
        $this->submit('/deconnexion', [], '/securite/application');

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testRegisteringAgainAnUnconfirmedAddressReplacesThePasswordAndOldLinks(): void
    {
        $this->register(self::NEW_EMAIL, 'le mot de passe d\'un intrus');
        $intruderLink = $this->confirmationLink(self::NEW_EMAIL);
        self::clock()->sleep(60);

        $this->register(self::NEW_EMAIL, self::NEW_PASSWORD);
        $link = $this->confirmationLink(self::NEW_EMAIL);
        $this->client->request('GET', $intruderLink);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'l\'ancien lien ne fonctionne plus');

        $this->submit($link, ['password' => self::NEW_PASSWORD], $link);
        self::assertResponseRedirects('/securite/application', Response::HTTP_SEE_OTHER);
    }

    public function testExistingAddressGetsTheSameAnswerAndAnInformativeEmail(): void
    {
        $this->register(self::EMAIL, self::NEW_PASSWORD);

        self::assertResponseRedirects('/inscription/envoyee', Response::HTTP_SEE_OTHER);
        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailAddressContains($email, 'To', self::EMAIL);
        self::assertEmailTextBodyContains($email, 'qui a déjà un');
        self::assertEmailTextBodyNotContains($email, '/inscription/confirmation/');
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM users'), 'aucun compte créé');

        // Same page as for a new address: nothing says that this one already has an account.
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'Vérifiez votre messagerie');
        self::assertSelectorTextContains('main p', 'Un e-mail vient d\'être envoyé à ' . self::EMAIL . '.');
    }

    public function testSentPageWithoutARecentRegistrationStaysGeneric(): void
    {
        $this->client->request('GET', '/inscription/envoyee');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main p', 'Un e-mail vient d\'être envoyé à l\'adresse indiquée.');
    }

    public function testInvalidInputIsReportedWithoutSendingAnything(): void
    {
        $this->register('pas-une-adresse', 'court', 'autre');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('#err-email', 'Adresse e-mail invalide.');
        self::assertSelectorTextContains('#err-password', 'au moins 12 caractères');

        $this->register(self::NEW_EMAIL, 'motdepasse123');
        self::assertSelectorTextContains('#err-password', 'trop prévisible');

        $this->register(self::NEW_EMAIL, self::NEW_PASSWORD, 'une autre phrase de passe');
        self::assertSelectorTextContains('#err-password_confirmation', 'ne correspondent pas');
        self::assertInputValueSame('email', self::NEW_EMAIL);
        self::assertEmailCount(0);
    }

    /** Bug: in English, the message for a too short password stayed in French (text built before translation). */
    public function testErrorsAreTranslatedOnTheEnglishPage(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'en-GB,en;q=0.9');
        $this->register(self::NEW_EMAIL, 'court');

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextSame('#err-password', 'The password must be at least 12 characters long.');
    }

    public function testRegistrationsAreLimitedPerIpAddress(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->register("personne$i@example.test", self::NEW_PASSWORD);
            self::assertResponseRedirects('/inscription/envoyee', Response::HTTP_SEE_OTHER);
        }

        $this->register('une-de-trop@example.test', self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSelectorTextContains('#err-email', 'Trop d\'inscriptions');
        self::assertNull(self::service(UserRepository::class)->findByEmail('une-de-trop@example.test'));
    }

    public function testRegistrationsFromManyAddressesAreCappedTogether(): void
    {
        // A script that changes its IP address: 10 per address are allowed, but 30 per hour in all.
        for ($i = 1; $i <= 30; ++$i) {
            $this->client->setServerParameter('REMOTE_ADDR', '203.0.113.' . intdiv($i, 10));
            $this->register("robot$i@example.test", self::NEW_PASSWORD);
            self::assertResponseRedirects('/inscription/envoyee', Response::HTTP_SEE_OTHER, (string) $i);
        }

        $this->client->setServerParameter('REMOTE_ADDR', '198.51.100.7');
        $this->register('robot-31@example.test', self::NEW_PASSWORD);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertNull(self::service(UserRepository::class)->findByEmail('robot-31@example.test'));
    }

    public function testRepeatedRegistrationsOfOneAddressSendAtMostThreeEmailsPerHour(): void
    {
        $sent = 0;
        for ($i = 1; $i <= 5; ++$i) {
            $this->register(self::EMAIL, self::NEW_PASSWORD);
            $sent += count(self::getMailerMessages());
        }

        self::assertSame(3, $sent);
    }

    public function testConfirmationAttemptsAreThrottled(): void
    {
        $this->register(self::NEW_EMAIL, self::NEW_PASSWORD);
        $link = $this->confirmationLink(self::NEW_EMAIL);

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->submit($link, ['password' => 'faux mot de passe'], $link);
        }
        $this->submit($link, ['password' => self::NEW_PASSWORD], $link);
        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    public function testLoggedInUserIsSentToTheDashboard(): void
    {
        $this->logIn();
        $this->client->request('GET', '/inscription');

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
    }

    public function testNewUserCanLogInOnlyAfterConfirmingTheAddress(): void
    {
        $this->register(self::NEW_EMAIL, self::NEW_PASSWORD);
        $this->submitPassword(self::NEW_EMAIL, self::NEW_PASSWORD);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSelectorTextContains('.flash-error', 'Confirmez d\'abord votre adresse e-mail');
    }

    private function register(string $email, string $password, ?string $confirmation = null): void
    {
        $this->submit('/inscription', [
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $confirmation ?? $password,
        ], '/inscription');
    }

    /** Link of the confirmation email sent to this address by the last request. */
    private function confirmationLink(string $email): string
    {
        $link = null;
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && $message->getTo()[0]->getAddress() === $email
                && preg_match('~https?://\S+/inscription/confirmation/\S+~', (string) $message->getTextBody(), $match) === 1) {
                $link = $match[0];
            }
        }
        self::assertIsString($link, "aucun lien de confirmation envoyé à $email");

        return $link;
    }
}
