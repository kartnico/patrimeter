<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Month;
use Patrimeter\Http\LocalePreference;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;
use Symfony\Component\HttpFoundation\Response;

/** Interface in French or in English: browser language, remembered choice, formats and emails. */
final class LocaleTest extends FunctionalTestCase
{
    public function testFrenchByDefaultWithASelectorToEnglish(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextContains('h1', 'Connexion');
        // Current language shown, menu of languages each named in its own language, the current language ticked.
        self::assertSelectorTextContains('details.locale-menu > summary', 'FR');
        self::assertSelectorTextContains('details.locale-menu > summary', 'Langue du site : Français');
        self::assertSelectorExists('form.locale-form[action="/langue"] button[name="locale"][value="en"][lang="en"]');
        self::assertSelectorTextContains('form.locale-form button[value="en"]', 'English');
        self::assertSelectorExists('form.locale-form button[value="fr"][aria-current="true"]');
        self::assertSelectorNotExists('form.locale-form button[value="en"][aria-current]');
        self::assertSelectorExists('form.locale-form input[name="_return"][value="/connexion"]');
    }

    public function testBrowserLanguageIsFollowed(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'en-GB,en;q=0.9');
        $crawler = $this->client->request('GET', '/connexion');

        self::assertSame('en', $crawler->filter('html')->attr('lang'));
        self::assertSelectorTextContains('h1', 'Sign in');
        self::assertSelectorTextContains('details.locale-menu > summary', 'EN');
        self::assertSelectorTextContains('details.locale-menu > summary', 'Site language: English');
        self::assertSelectorExists('form.locale-form button[name="locale"][value="en"][aria-current="true"]');
        self::assertSelectorTextContains('form.locale-form button[value="fr"]', 'Français');

        // Language not offered: French.
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'de-DE,de;q=0.9');
        self::assertSame('fr', $this->client->request('GET', '/connexion')->filter('html')->attr('lang'));
    }

    public function testChosenLanguageIsRememberedAndWinsOverTheBrowser(): void
    {
        $this->client->request('POST', '/langue', ['_csrf' => $this->csrfToken('/connexion'), 'locale' => 'en', '_return' => '/connexion']);

        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
        $cookie = $this->client->getResponse()->headers->getCookies()[0] ?? null;
        self::assertInstanceOf(ResponseCookie::class, $cookie);
        self::assertSame(LocalePreference::COOKIE, $cookie->getName());
        self::assertSame('en', $cookie->getValue());
        self::assertTrue($cookie->isHttpOnly());

        $crawler = $this->client->request('GET', '/connexion');
        self::assertSame('en', $crawler->filter('html')->attr('lang'), 'le choix l\'emporte sur le navigateur, réglé en français');
    }

    public function testUnknownLanguageTogglesAndForeignReturnIsRefused(): void
    {
        $this->client->getCookieJar()->set(new Cookie(LocalePreference::COOKIE, 'en'));
        $this->client->request('POST', '/langue', ['_csrf' => $this->csrfToken('/connexion'), 'locale' => 'xx', '_return' => 'https://exemple.test/']);

        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        self::assertSame('fr', $this->client->getResponse()->headers->getCookies()[0]->getValue());
    }

    public function testDashboardInEnglishUsesEnglishFormatsAndLabels(): void
    {
        $this->logIn();
        $id = self::createAccount('PEA', 'pea', '2020-01-01');
        self::service(StatementRepository::class)->save(self::owner(), $id, Month::fromString('2026-11'), 12_345_678);
        $this->client->getCookieJar()->set(new Cookie(LocalePreference::COOKIE, 'en'));

        $crawler = $this->client->request('GET', '/');

        self::assertSame('Net worth November 2026', trim(preg_replace('/\s+/', ' ', $crawler->filter('h1')->text()) ?? ''));
        self::assertSame('€123,456.78', trim($crawler->filter('.summary-total')->text()));
        self::assertSelectorTextContains('.levels-card', 'Equities');
        self::assertStringContainsString('Equities', $crawler->filter('svg.pyramid')->html());
        self::assertSelectorTextContains('#world-rank-title', 'Among adults worldwide');

        $this->client->request('GET', '/securite');
        self::assertSelectorTextContains('.profile', 'Password, then authenticator app');
        self::assertSelectorTextContains('nav.nav', 'Net worth');
        self::assertSelectorTextContains('nav.account-tabs', 'Real estate');
    }

    public function testEmailIsWrittenInTheLanguageOfTheRequest(): void
    {
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'en');
        $this->submit('/mot-de-passe-oublie', ['email' => self::EMAIL], '/mot-de-passe-oublie');

        self::assertEmailCount(1);
        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailHeaderSame($email, 'Subject', 'Patrimeter: password reset');
        self::assertEmailTextBodyContains($email, 'To choose a new Patrimeter password, open this link:');
    }
}
