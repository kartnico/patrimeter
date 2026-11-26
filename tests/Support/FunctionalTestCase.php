<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side end-to-end test: real HTTP requests handled by the kernel, test database rolled back after each test,
 * clock frozen on 15 November 2026 at noon (current month 2026-11). The kernel is not rebooted between two requests
 * of the same test: the clock and the session move on as for a real browser.
 */
abstract class FunctionalTestCase extends WebTestCase
{
    use CreatesAccounts;

    protected const string EMAIL = TestUsers::EMAIL;
    protected const string PASSWORD = TestUsers::PASSWORD;

    protected KernelBrowser $client;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient(static::clientOptions());
        $this->client->disableReboot();
        // Request::create() announces "en-us" by default: the tests speak French, like the default interface.
        $this->client->setServerParameter('HTTP_ACCEPT_LANGUAGE', 'fr-FR,fr;q=0.9');
        self::owner();
    }

    /** @return array{debug?: bool} kernel options (debug disabled to see the real error pages) */
    protected static function clientOptions(): array
    {
        return [];
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected static function service(string $id): object
    {
        $service = static::getContainer()->get($id);
        self::assertInstanceOf($id, $service);

        return $service;
    }

    protected static function clock(): MockClock
    {
        // Frozen clock of the test container (config/services.yaml, when@test).
        return static::getContainer()->get('clock');
    }

    /** Full sign-in: address and password, then TOTP code. */
    protected function logIn(string $email = self::EMAIL, string $password = self::PASSWORD): void
    {
        $this->submitPassword($email, $password);
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
        $this->client->followRedirect();
        self::assertResponseRedirects('/connexion/code');
        $this->submitCode(TestUsers::totpCode(static::getContainer()));
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);
    }

    /** First factor: sign-in form. */
    protected function submitPassword(string $email = self::EMAIL, string $password = self::PASSWORD): void
    {
        $this->client->request('POST', '/connexion', ['_csrf' => $this->csrfToken('/connexion'), 'email' => $email, 'password' => $password]);
    }

    /** Second factor: TOTP code or backup code. */
    protected function submitCode(string $code): void
    {
        $this->client->request('POST', '/connexion/code', ['_csrf' => $this->csrfToken('/connexion/code'), 'code' => $code]);
    }

    /** CSRF token present in the page (same token for all the forms of the session). */
    protected function csrfToken(string $page = '/'): string
    {
        $crawler = $this->client->request('GET', $page);
        $token = $crawler->filter('input[name="_csrf"]')->first()->attr('value');
        self::assertIsString($token, "Aucun jeton CSRF dans $page");

        return $token;
    }

    /**
     * POST of a form with a valid CSRF token, read in the $tokenPage page.
     *
     * @param array<string, mixed> $fields
     */
    protected function submit(string $path, array $fields = [], string $tokenPage = '/'): void
    {
        $this->client->request('POST', $path, ['_csrf' => $this->csrfToken($tokenPage)] + $fields);
    }

    /**
     * Replaces the browser's session with another one (saved with saveSession()): simulates two devices signed in to
     * the same account.
     *
     * @param list<\Symfony\Component\BrowserKit\Cookie> $cookies
     */
    protected function restoreSession(array $cookies): void
    {
        $jar = $this->client->getCookieJar();
        $jar->clear();
        foreach ($cookies as $cookie) {
            $jar->set($cookie);
        }
    }

    /** @return list<\Symfony\Component\BrowserKit\Cookie> */
    protected function saveSession(): array
    {
        return array_values($this->client->getCookieJar()->all());
    }

    protected function body(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /** Flash messages of the current page. */
    protected function flashes(): string
    {
        $crawler = $this->client->getCrawler();

        return implode(' | ', $crawler->filter('.flash')->each(static fn($node): string => trim($node->text())));
    }
}
