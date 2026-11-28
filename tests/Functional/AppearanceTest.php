<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Http\Appearance;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class AppearanceTest extends FunctionalTestCase
{
    public function testWithoutChoiceThePageHasTheDefaultLook(): void
    {
        $crawler = $this->client->request('GET', '/connexion');

        self::assertNull($crawler->filter('html')->attr('data-accent'));
        self::assertNull($crawler->filter('html')->attr('data-density'));
        self::assertSelectorExists('meta[name="theme-color"][content="#2f6f5e"]');
    }

    public function testTheChosenLookIsRenderedByTheServerOnEveryPage(): void
    {
        $this->client->getCookieJar()->set(new Cookie(Appearance::ACCENT_COOKIE, 'ocean'));
        $this->client->getCookieJar()->set(new Cookie(Appearance::DENSITY_COOKIE, 'compact'));
        $crawler = $this->client->request('GET', '/connexion');

        // In the initial HTML: no flash of the default look at load.
        self::assertSame('ocean', $crawler->filter('html')->attr('data-accent'));
        self::assertSame('compact', $crawler->filter('html')->attr('data-density'));
        self::assertSelectorExists('meta[name="theme-color"][content="#1f5f8b"]');
    }

    public function testUnknownCookieValuesAreIgnored(): void
    {
        $this->client->getCookieJar()->set(new Cookie(Appearance::ACCENT_COOKIE, '"><script>'));
        $crawler = $this->client->request('GET', '/connexion');

        self::assertNull($crawler->filter('html')->attr('data-accent'));
    }

    public function testMyProfileOffersEachAccentAndDensityWithTheCurrentOneChecked(): void
    {
        $this->logIn();
        $this->client->getCookieJar()->set(new Cookie(Appearance::DENSITY_COOKIE, 'compact'));
        $this->client->request('GET', '/securite');

        self::assertSelectorTextContains('#appearance-title', 'Apparence');
        self::assertSelectorCount(4, 'form[action="/securite/apparence"] input[name="accent"]');
        self::assertSelectorExists('input[name="accent"][value="forest"][checked]');
        self::assertSelectorExists('input[name="density"][value="compact"][checked]');
        self::assertSelectorNotExists('input[name="density"][value="comfortable"][checked]');
        // Each colour is named: the swatch is decorative.
        self::assertSelectorExists('.accent-choice .accent-swatch-ocean[aria-hidden="true"]');
        self::assertSelectorTextContains('.accent-choices', 'Ardoise');
    }

    public function testSavingKeepsTheLookOnThisBrowserAndShowsIt(): void
    {
        $this->logIn();
        $this->submit('/securite/apparence', ['accent' => 'aurora', 'density' => 'compact'], '/securite');

        self::assertResponseRedirects('/securite#appearance-title', Response::HTTP_SEE_OTHER);
        $cookies = [];
        foreach ($this->client->getResponse()->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
        self::assertSame('aurora', $cookies[Appearance::ACCENT_COOKIE] ?? null);
        self::assertSame('compact', $cookies[Appearance::DENSITY_COOKIE] ?? null);

        $crawler = $this->client->followRedirect();
        self::assertSame('aurora', $crawler->filter('html')->attr('data-accent'));
        self::assertSame('compact', $crawler->filter('html')->attr('data-density'));
        self::assertSelectorTextContains('.flash-success', 'Apparence enregistrée pour ce navigateur.');
        self::assertSelectorExists('input[name="accent"][value="aurora"][checked]');
    }

    public function testBackToTheDefaultLookLeavesNoAttribute(): void
    {
        $this->logIn();
        $this->client->getCookieJar()->set(new Cookie(Appearance::ACCENT_COOKIE, 'slate'));
        $this->submit('/securite/apparence', ['accent' => 'forest', 'density' => 'comfortable'], '/securite');
        $crawler = $this->client->followRedirect();

        self::assertNull($crawler->filter('html')->attr('data-accent'));
        self::assertNull($crawler->filter('html')->attr('data-density'));
    }

    public function testAnUnknownChoiceKeepsTheCurrentLook(): void
    {
        $this->logIn();
        $this->client->getCookieJar()->set(new Cookie(Appearance::ACCENT_COOKIE, 'slate'));
        $this->submit('/securite/apparence', ['accent' => 'purple', 'density' => 'compact'], '/securite');
        $crawler = $this->client->followRedirect();

        self::assertSame('slate', $crawler->filter('html')->attr('data-accent'));
        self::assertSame('compact', $crawler->filter('html')->attr('data-density'));
    }
}
