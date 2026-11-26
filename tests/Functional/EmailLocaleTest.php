<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/**
 * Language of the emails chosen in the profile, and advice of the security notices: an action that may have shut the
 * user out (password changed, account deleted) never advises to change the password from My account.
 */
final class EmailLocaleTest extends FunctionalTestCase
{
    private const string NEW_PASSWORD = 'une toute nouvelle phrase de passe';

    public function testTheProfileSavesTheLanguageOfTheEmails(): void
    {
        $this->logIn();
        $this->client->request('GET', '/securite');
        self::assertSelectorExists('select#email-locale option[value=""][selected]');

        $this->submit('/securite/langue-des-e-mails', ['email_locale' => 'en'], '/securite');
        self::assertResponseRedirects('/securite#email-locale-title', Response::HTTP_SEE_OTHER);
        self::assertSame('en', self::service(UserRepository::class)->emailLocale(self::owner()));
        $this->client->request('GET', '/securite');
        self::assertSelectorExists('select#email-locale option[value="en"][selected]');

        $this->submit('/securite/langue-des-e-mails', ['email_locale' => 'de'], '/securite');
        self::assertNull(self::service(UserRepository::class)->emailLocale(self::owner()), 'unknown language: that of the interface');
    }

    public function testASecurityNoticeIsWrittenInTheChosenLanguageAndPointsToTheAdministrator(): void
    {
        $this->logIn();
        self::service(UserRepository::class)->saveEmailLocale(self::owner(), 'en');

        $this->changePassword();

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailHeaderSame($email, 'Subject', 'Patrimeter: password changed');
        self::assertEmailTextBodyContains($email, 'Your account password has been changed.');
        self::assertEmailTextBodyContains($email, 'tell the server administrator');
        self::assertEmailTextBodyNotContains($email, 'change your password');
    }

    public function testWithoutAChoiceTheEmailFollowsTheInterface(): void
    {
        $this->logIn();

        $this->changePassword();

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailHeaderSame($email, 'Subject', 'Patrimeter : mot de passe modifié');
        self::assertEmailTextBodyContains($email, 'prévenez sans attendre l\'administrateur du serveur');
        self::assertEmailTextBodyNotContains($email, 'changez votre mot de passe');
    }

    public function testTheDeletionNoticeKeepsTheLanguageOfTheDeletedAccount(): void
    {
        $this->logIn();
        self::service(UserRepository::class)->saveEmailLocale(self::owner(), 'en');

        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite'], '/securite');
        $this->submit('/securite/supprimer-le-compte', ['confirm_email' => self::EMAIL], '/securite');

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertEmailHeaderSame($email, 'Subject', 'Patrimeter: account deleted');
        self::assertEmailTextBodyContains($email, 'tell the server administrator');
        self::assertEmailTextBodyNotContains($email, 'change your password');
    }

    private function changePassword(): void
    {
        $this->submit('/securite/mot-de-passe', [
            'current_password' => self::PASSWORD,
            'new_password' => self::NEW_PASSWORD,
            'new_password_confirmation' => self::NEW_PASSWORD,
        ], '/securite');
        self::assertResponseRedirects('/securite', Response::HTTP_SEE_OTHER);
    }
}
