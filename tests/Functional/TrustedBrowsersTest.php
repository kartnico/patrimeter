<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Infrastructure\Persistence\UserRepository;
use Patrimeter\Security\TwoFactor\TrustedBrowsers;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Trusted browsers: "trust this browser" ticked on the code screen, the next sign-ins there no longer ask for the code,
 * for 30 days and as long as the credentials stay the same; removed from My account, one by one or all together.
 */
final class TrustedBrowsersTest extends FunctionalTestCase
{
    private const string COOKIE = 'patrimeter_trusted';

    public function testATrustedBrowserNoLongerAsksForTheCode(): void
    {
        $this->signInTrusting();
        $cookie = $this->client->getCookieJar()->get(self::COOKIE);
        self::assertNotNull($cookie, 'cookie of the trusted browser');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', strtolower((string) $cookie->getSameSite()));
        self::assertMatchesRegularExpression('/\A[0-9a-f]{32}\.[A-Za-z0-9_-]{43}\z/', $cookie->getValue());
        // Only the fingerprint of the secret is stored, never the cookie.
        $row = self::service(Connection::class)->fetchAssociative('SELECT * FROM trusted_browsers');
        self::assertIsArray($row);
        self::assertStringNotContainsString(explode('.', $cookie->getValue())[1], json_encode($row, JSON_THROW_ON_ERROR));

        $this->logOut();
        self::assertTrue($this->signInWithoutCode(), 'password only');
        $journal = $this->client->request('GET', '/securite/connexion')->filter('#journal')->text();
        self::assertStringContainsString('Navigateur ajouté aux navigateurs de confiance', $journal);
        self::assertStringContainsString('Connexion sans code (navigateur de confiance)', $journal);
    }

    public function testWithoutTheBoxTheCodeIsAskedAgain(): void
    {
        $this->logIn();
        self::assertNull($this->client->getCookieJar()->get(self::COOKIE));
        $this->logOut();
        self::assertFalse($this->signInWithoutCode());
    }

    public function testTheTrustEndsAfter30DaysOrWhenTheCredentialsChange(): void
    {
        $this->signInTrusting();
        $this->logOut();
        self::clock()->sleep(31 * 86400);
        self::assertFalse($this->signInWithoutCode(), 'expired');

        $this->client->getCookieJar()->clear();
        self::clock()->sleep(30);
        $this->signInTrusting();
        $this->logOut();
        // A new password or the other sessions signed out renew the session stamp: the trust falls.
        self::service(UserRepository::class)->renewSessionStamp(self::owner());
        self::assertFalse($this->signInWithoutCode(), 'credentials changed');
    }

    public function testTheCookieIsWorthNothingForAnotherAccount(): void
    {
        $this->signInTrusting();
        $value = $this->client->getCookieJar()->get(self::COOKIE)?->getValue();
        self::assertIsString($value);
        $this->logOut();
        TestUsers::findOrCreate(self::getContainer(), TestUsers::OTHER_EMAIL);
        $this->client->getCookieJar()->clear();
        $this->client->getCookieJar()->set(new Cookie(self::COOKIE, $value));

        self::assertFalse($this->signInWithoutCode(TestUsers::OTHER_EMAIL));
    }

    public function testATrustedBrowserIsListedAndRemoved(): void
    {
        $this->signInTrusting();
        $crawler = $this->client->request('GET', '/securite/connexion');
        self::assertCount(1, $crawler->filter('#trusted-browsers tbody tr'));
        self::assertStringContainsString('ce navigateur', $crawler->filter('#trusted-browsers tr.is-current')->text());
        self::assertSelectorNotExists('#trusted-browsers form', 'identity not confirmed yet');

        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite/connexion'], '/securite/connexion');
        $crawler = $this->client->request('GET', '/securite/connexion');
        $this->submit((string) $crawler->filter('#trusted-browsers tbody form')->attr('action'), [], '/securite/connexion');
        self::assertResponseRedirects('/securite/connexion#trusted-browsers', Response::HTTP_SEE_OTHER);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Ce navigateur n\'est plus de confiance', $this->flashes());
        self::assertSelectorTextContains('#trusted-browsers', 'Aucun navigateur de confiance.');
        self::assertStringContainsString('Navigateur de confiance retiré', $crawler->filter('#journal')->text());

        $this->logOut();
        self::assertFalse($this->signInWithoutCode(), 'removed: the code again');
    }

    public function testAllTheTrustedBrowsersAreRemovedTogether(): void
    {
        $this->signInTrusting();
        $this->submit('/securite/confirmer', ['password' => self::PASSWORD, '_return' => '/securite/connexion'], '/securite/connexion');
        $this->submit('/securite/navigateurs/retirer', [], '/securite/connexion');
        self::assertResponseRedirects('/securite/connexion#trusted-browsers', Response::HTTP_SEE_OTHER);
        self::assertFalse(self::service(Connection::class)->fetchOne('SELECT 1 FROM trusted_browsers'), 'none left');
    }

    public function testTheSecurityKeyFormTakesTheBoxAlong(): void
    {
        $this->submitPassword();
        $crawler = $this->client->request('GET', '/connexion/code');
        self::assertCount(1, $crawler->filter('input[type="checkbox"][name="' . TrustedBrowsers::FIELD . '"]'));
        self::assertStringContainsString('Faire confiance à ce navigateur pendant 30 jours', $crawler->filter('label[for="trust-browser"]')->text());
    }

    private function signInTrusting(): void
    {
        $this->submitPassword();
        $this->client->followRedirect();
        self::assertResponseRedirects('/connexion/code');
        $this->client->request('POST', '/connexion/code', [
            '_csrf' => $this->csrfToken('/connexion/code'),
            'code' => TestUsers::totpCode(static::getContainer()),
            TrustedBrowsers::FIELD => '1',
        ]);
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
    }

    /** Password only: true if the account opens without the code screen. */
    private function signInWithoutCode(string $email = self::EMAIL): bool
    {
        $this->submitPassword($email);
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();

        $response = $this->client->getResponse();

        return !($response->isRedirection() && str_ends_with((string) $response->headers->get('Location'), '/connexion/code'));
    }

    private function logOut(): void
    {
        $this->submit('/deconnexion', [], '/securite/connexion');
        // The same TOTP code is accepted only once: the next sign-in comes 30 seconds later.
        self::clock()->sleep(30);
    }
}
