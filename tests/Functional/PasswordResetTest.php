<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Security\Vault\RecoveryKey;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/** Forgotten password: with the recovery key the data stays, without it the data is lost. */
final class PasswordResetTest extends FunctionalTestCase
{
    private const string NEW_PASSWORD = 'une toute nouvelle phrase de passe';
    private const string MARKER = 'Livret-Secret-5e2c';

    public function testSameAnswerWhetherTheAddressExistsOrNot(): void
    {
        $this->submit('/mot-de-passe-oublie', ['email' => 'inconnu@example.test'], '/mot-de-passe-oublie');
        self::assertResponseRedirects('/mot-de-passe-oublie/envoye', Response::HTTP_SEE_OTHER);
        self::assertEmailCount(0);

        $this->submit('/mot-de-passe-oublie', ['email' => self::EMAIL], '/mot-de-passe-oublie');
        self::assertResponseRedirects('/mot-de-passe-oublie/envoye', Response::HTTP_SEE_OTHER);
        self::assertEmailCount(1);
    }

    public function testUnconfirmedAddressReceivesNothing(): void
    {
        TestUsers::create(static::getContainer(), 'nouveau@example.test', verified: false, totp: false);

        $this->submit('/mot-de-passe-oublie', ['email' => 'nouveau@example.test'], '/mot-de-passe-oublie');

        self::assertEmailCount(0);
    }

    public function testRequestsAreLimitedPerIpAddress(): void
    {
        for ($i = 1; $i <= 10; ++$i) {
            $this->submit('/mot-de-passe-oublie', ['email' => "inconnu$i@example.test"], '/mot-de-passe-oublie');
        }
        $this->submit('/mot-de-passe-oublie', ['email' => self::EMAIL], '/mot-de-passe-oublie');

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertSelectorTextContains('.flash-error', 'Trop de demandes.');
    }

    public function testTheNewPasswordShowsItsStrengthAndRequirements(): void
    {
        $this->client->request('GET', $this->resetLink());

        self::assertSelectorExists('#password-meter[data-for="password"][data-confirm="password_confirmation"]');
        self::assertSelectorExists('#password-meter [data-rule="length"]');
        self::assertSelectorNotExists('#password-meter [data-rule="email"]', 'address not shown on this public page');
        self::assertSame('password-meter', $this->client->getCrawler()->filter('#password')->attr('aria-describedby'));
    }

    /** An account without password (created by an external provider before 2.14.0) gets one back, data kept. */
    public function testAnAccountWithoutPasswordGetsOneWithItsRecoveryKey(): void
    {
        self::createAccount(self::MARKER, 'livret_a');
        self::service(Connection::class)->executeStatement('UPDATE users SET password_hash = NULL WHERE id = ?', [self::owner()->value]);
        $link = $this->resetLink();

        $this->resetWith($link, ['recovery_key' => self::recoveryKey(), 'code' => TestUsers::totpCode(static::getContainer())]);
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);

        self::clock()->sleep(60);
        $this->logIn(self::EMAIL, self::NEW_PASSWORD);
        $this->client->request('GET', '/comptes');
        self::assertStringContainsString(self::MARKER, $this->body());
    }

    public function testRecoveryKeyAndCodeKeepTheDataAndTheSecondFactor(): void
    {
        self::createAccount(self::MARKER, 'livret_a');
        $link = $this->resetLink();

        $this->resetWith($link, ['recovery_key' => self::recoveryKey(), 'code' => TestUsers::totpCode(static::getContainer())]);
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        self::assertEmailCount(1);
        self::assertSame(1, self::queryInt("SELECT COUNT(*) FROM auth_events WHERE type = 'password_reset'"));

        self::clock()->sleep(60);
        $this->logIn(self::EMAIL, self::NEW_PASSWORD);
        $this->client->request('GET', '/comptes');
        self::assertStringContainsString(self::MARKER, $this->body());
    }

    public function testRecoveryKeyAloneKeepsTheDataButResetsTheSecondFactor(): void
    {
        self::createAccount(self::MARKER, 'livret_a');
        $this->resetWith($this->resetLink(), ['recovery_key' => self::recoveryKey()]);
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);

        $this->submitPassword(self::EMAIL, self::NEW_PASSWORD);
        $this->client->followRedirect();
        self::assertResponseRedirects('/securite/application', Response::HTTP_SEE_OTHER, 'nouvelle application à configurer');
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM backup_codes WHERE user_id = ?', [self::owner()->value]));
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM accounts'));
    }

    public function testWithoutRecoveryKeyDataLossMustBeAcceptedAndTheCodeGiven(): void
    {
        $account = self::createAccount(self::MARKER, 'livret_a');
        $link = $this->resetLink();

        $this->resetWith($link, ['code' => TestUsers::totpCode(static::getContainer())]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSelectorTextContains('.flash-error', 'perte définitive');

        $this->resetWith($link, ['accept_data_loss' => '1', 'code' => '000000']);
        self::assertSelectorTextContains('.flash-error', 'Code incorrect.');
        self::assertSame(1, self::queryInt('SELECT COUNT(*) FROM accounts WHERE id = ?', [$account]), 'rien n\'a été effacé');

        self::clock()->sleep(30);
        $this->resetWith($link, ['accept_data_loss' => '1', 'code' => TestUsers::totpCode(static::getContainer())]);
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM accounts WHERE id = ?', [$account]), 'données effacées');
        self::assertSame(0, self::queryInt('SELECT COUNT(*) FROM key_envelopes WHERE user_id = ?', [self::owner()->value]));
        self::assertSame(1, self::queryInt("SELECT COUNT(*) FROM auth_events WHERE type = 'data_erased'"));

        self::clock()->sleep(60);
        $this->logIn(self::EMAIL, self::NEW_PASSWORD);
        $this->client->followRedirect();
        self::assertResponseRedirects('/securite/cle-de-recuperation', Response::HTTP_SEE_OTHER, 'nouvelle clé de données, nouvelle clé de récupération');
    }

    public function testWrongRecoveryKeyIsRefusedAndThrottled(): void
    {
        $link = $this->resetLink();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $this->resetWith($link, ['recovery_key' => RecoveryKey::format(RecoveryKey::generate())]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
            self::assertSelectorTextContains('.flash-error', 'Clé de récupération incorrecte.');
        }

        $this->resetWith($link, ['recovery_key' => self::recoveryKey()]);
        self::assertSelectorTextContains('.flash-error', 'Trop de tentatives.');
    }

    public function testInvalidNewPasswordIsRefused(): void
    {
        $link = $this->resetLink();

        $this->resetWith($link, ['recovery_key' => self::recoveryKey(), 'password' => 'court', 'password_confirmation' => 'court']);
        self::assertSelectorTextContains('.flash-error', 'au moins 12 caractères');
        $this->resetWith($link, ['recovery_key' => self::recoveryKey(), 'password_confirmation' => 'une autre confirmation']);
        self::assertSelectorTextContains('.flash-error', 'ne correspondent pas');
    }

    public function testLinkWorksOnceExpiresAfterThirtyMinutesAndEndsSessions(): void
    {
        $this->logIn();
        $session = $this->saveSession();
        $this->client->getCookieJar()->clear();
        $link = $this->resetLink();
        $this->resetWith($link, ['recovery_key' => self::recoveryKey()]);

        $this->client->request('GET', $link);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSelectorTextContains('h1', 'Lien invalide ou expiré');

        $this->restoreSession($session);
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER, 'sessions ouvertes fermées');

        $this->client->getCookieJar()->clear();
        self::clock()->sleep(3600);
        $expired = $this->resetLink();
        self::clock()->sleep(1801);
        $this->client->request('GET', $expired);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->client->request('POST', $expired, ['_csrf' => $this->csrfToken('/mot-de-passe-oublie')]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testANewRequestInvalidatesThePreviousLink(): void
    {
        $first = $this->resetLink();
        $this->resetLink();

        $this->client->request('GET', $first);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param array<string, string> $fields */
    private function resetWith(string $link, array $fields): void
    {
        $this->client->request('POST', $link, ['_csrf' => $this->csrfToken('/mot-de-passe-oublie')] + $fields + ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
    }

    private function resetLink(): string
    {
        $this->submit('/mot-de-passe-oublie', ['email' => self::EMAIL], '/mot-de-passe-oublie');
        foreach (self::getMailerMessages() as $message) {
            if ($message instanceof Email && preg_match('~https?://\S+/mot-de-passe-oublie/\S+~', (string) $message->getTextBody(), $match) === 1) {
                return $match[0];
            }
        }
        self::fail('Aucun lien envoyé');
    }

    private static function recoveryKey(): string
    {
        return RecoveryKey::format(TestUsers::RECOVERY_KEY);
    }
}
