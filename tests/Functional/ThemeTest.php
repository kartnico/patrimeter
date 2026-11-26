<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Http\ThemePreference;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Cookie as ResponseCookie;
use Symfony\Component\HttpFoundation\Response;

final class ThemeTest extends FunctionalTestCase
{
    public function testWithoutChoiceThePageFollowsTheSystemTheme(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        self::assertNull($crawler->filter('html')->attr('data-theme'), 'aucun data-theme : thème du système');
        self::assertSelectorExists('form.theme-form[action="/theme"] button[name="theme"][value="toggle"]');
        self::assertSelectorExists('form.theme-form input[name="_return"][value="/connexion"]');
    }

    public function testChosenThemeIsRenderedByTheServer(): void
    {
        $this->client->getCookieJar()->set(new Cookie(ThemePreference::COOKIE, 'dark'));
        $crawler = $this->client->request('GET', '/connexion');

        // The right theme is in the initial HTML: no flash of the wrong theme at load.
        self::assertSame('dark', $crawler->filter('html')->attr('data-theme'));
    }

    public function testUnknownCookieValueIsIgnored(): void
    {
        $this->client->getCookieJar()->set(new Cookie(ThemePreference::COOKIE, '"><script>'));
        $crawler = $this->client->request('GET', '/connexion');

        self::assertNull($crawler->filter('html')->attr('data-theme'));
    }

    public function testSwitchingWithoutJavascriptStoresTheChoiceForAYear(): void
    {
        $this->logIn();
        $this->submit('/theme', ['theme' => 'toggle', '_return' => '/?periode=tout']);

        self::assertResponseRedirects('/?periode=tout', Response::HTTP_SEE_OTHER);
        $cookie = $this->themeCookie();
        self::assertSame('dark', $cookie->getValue(), 'sans choix, la bascule passe au sombre');
        self::assertFalse($cookie->isHttpOnly(), 'app.js doit pouvoir le modifier');
        self::assertSame('strict', $cookie->getSameSite());
        self::assertSame('/', $cookie->getPath());
        self::assertEqualsWithDelta(strtotime('+1 year'), $cookie->getExpiresTime(), 5, 'one year in real time');

        $crawler = $this->client->followRedirect();
        self::assertSame('dark', $crawler->filter('html')->attr('data-theme'));
    }

    /**
     * Bug: the expiry came from the application's clock, frozen in the tests; once the real date passed it, the
     * browser dropped the cookie at once (the theme and language cookies would have broken on 2027-11-15).
     */
    public function testTheChoiceIsKeptWhateverTheApplicationClockSays(): void
    {
        self::clock()->modify('-2 years');
        $this->client->request('GET', '/connexion');
        $this->submit('/theme', ['theme' => 'dark', '_return' => '/connexion'], '/connexion');
        $this->submit('/langue', ['locale' => 'en', '_return' => '/connexion'], '/connexion');

        $crawler = $this->client->request('GET', '/connexion');
        self::assertSame('dark', $crawler->filter('html')->attr('data-theme'));
        self::assertSame('en', $crawler->filter('html')->attr('lang'));
    }

    public function testToggleAlternatesBetweenLightAndDark(): void
    {
        $this->client->getCookieJar()->set(new Cookie(ThemePreference::COOKIE, 'dark'));
        $this->client->request('POST', '/theme', ['_csrf' => $this->csrfToken('/connexion'), 'theme' => 'toggle', '_return' => '/connexion']);
        self::assertSame('light', $this->themeCookie()->getValue());

        $this->client->request('POST', '/theme', ['_csrf' => $this->csrfToken('/connexion'), 'theme' => 'toggle', '_return' => '/connexion']);
        self::assertSame('dark', $this->themeCookie()->getValue());
    }

    public function testExplicitThemeIsAccepted(): void
    {
        $this->client->request('POST', '/theme', ['_csrf' => $this->csrfToken('/connexion'), 'theme' => 'light', '_return' => '/connexion']);

        self::assertSame('light', $this->themeCookie()->getValue());
    }

    public function testReturnAddressMustBeInternal(): void
    {
        foreach (['https://evil.example/', '//evil.example', '/\\evil.example', '', "/a\nb"] as $return) {
            $this->client->request('POST', '/theme', ['_csrf' => $this->csrfToken('/connexion'), 'theme' => 'toggle', '_return' => $return]);
            self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER, "retour refusé : $return");
        }
    }

    public function testAnonymousVisitorCanSwitchButNeedsAValidToken(): void
    {
        $this->client->request('POST', '/theme', ['_csrf' => 'invalide', 'theme' => 'dark', '_return' => '/connexion']);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->client->request('POST', '/theme', ['_csrf' => $this->csrfToken('/connexion'), 'theme' => 'dark', '_return' => '/connexion']);
        self::assertResponseRedirects('/connexion', Response::HTTP_SEE_OTHER);
    }

    private function themeCookie(): ResponseCookie
    {
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            if ($cookie->getName() === ThemePreference::COOKIE) {
                return $cookie;
            }
        }
        self::fail('Cookie de thème absent de la réponse');
    }
}
