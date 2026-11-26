<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\RouteCatalog;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * Access control of every route, read from the router (a new route is checked automatically): anonymous visitor,
 * second factor pending, TOTP not set up yet. No refused request may change the database.
 */
final class AccessControlTest extends FunctionalTestCase
{
    public function testEveryRouteIsClassified(): void
    {
        foreach (array_keys(RouteCatalog::routes(self::service(RouterInterface::class))) as $name) {
            self::assertCount(1, RouteCatalog::categoriesOf($name), "Route « $name » à classer dans tests/Support/RouteCatalog.php");
        }
    }

    public function testEveryNonPublicRouteRequiresAuthentication(): void
    {
        $checked = $this->visitEveryRoute(
            [...RouteCatalog::PUBLIC, ...RouteCatalog::PUBLIC_WITH_CSRF],
            '/connexion',
            Response::HTTP_SEE_OTHER,
            withToken: false,
        );

        self::assertGreaterThanOrEqual(20, $checked, 'toutes les routes ont été parcourues');
    }

    public function testNothingButTheCodeFormIsReachableBeforeTheSecondFactor(): void
    {
        $this->submitPassword();

        $checked = $this->visitEveryRoute(
            [...RouteCatalog::PUBLIC, ...RouteCatalog::PUBLIC_WITH_CSRF, ...RouteCatalog::SECOND_FACTOR],
            '/connexion/code',
            null,
            tokenPage: '/connexion/code',
        );

        self::assertGreaterThanOrEqual(20, $checked);
    }

    public function testUserWithoutTotpCanOnlyConfigureIt(): void
    {
        TestUsers::create(static::getContainer(), 'sans-totp@example.test', totp: false);
        $this->submitPassword('sans-totp@example.test');
        self::assertResponseRedirects('/', Response::HTTP_SEE_OTHER);

        $checked = $this->visitEveryRoute(
            // Signing in (handled by the firewall), the theme and the language remain possible.
            [...RouteCatalog::ENROLLMENT, ...RouteCatalog::PUBLIC_WITH_CSRF, ...RouteCatalog::SECOND_FACTOR, 'app_login', 'app_passkey_login', 'app_theme', 'app_locale'],
            '/securite/application',
            Response::HTTP_SEE_OTHER,
            tokenPage: '/securite/application',
        );
        self::assertGreaterThanOrEqual(20, $checked);

        // No second factor in progress: the code form is refused.
        $this->client->request('GET', '/connexion/code');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->submit('/connexion/code', ['code' => '123456'], '/securite/application');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testLoginAndRegistrationPagesArePublic(): void
    {
        foreach (['/connexion', '/inscription', '/inscription/envoyee'] as $path) {
            $this->client->request('GET', $path);
            self::assertResponseIsSuccessful($path);
        }
    }

    /**
     * Goes through every route of the application except $except, with each of their methods: all must redirect
     * to $expectedLocation without changing anything.
     *
     * @param list<string> $except
     *
     * @return int number of requests checked
     */
    private function visitEveryRoute(array $except, string $expectedLocation, ?int $expectedStatus, bool $withToken = true, string $tokenPage = '/'): int
    {
        $before = $this->databaseState();
        $checked = 0;
        foreach (RouteCatalog::routes(self::service(RouterInterface::class)) as $name => $route) {
            if (in_array($name, [...$except, ...RouteCatalog::BEARER, ...RouteCatalog::OAUTH_ENDPOINTS, ...RouteCatalog::SERVICE_ENDPOINTS], true)) {
                continue;
            }
            foreach ($route->getMethods() as $method) {
                $parameters = $withToken && $method === 'POST' ? ['_csrf' => $this->csrfToken($tokenPage)] : [];
                $this->client->request($method, RouteCatalog::path($route), $parameters + ['name' => 'Intrus', 'type_id' => '3', 'opened_on' => '2026-01-01', 'mois' => '2026-11', 'montant' => '1']);
                self::assertResponseRedirects($expectedLocation, $expectedStatus, "$method {$route->getPath()} ($name)");
                ++$checked;
            }
        }
        self::assertSame($before, $this->databaseState(), 'aucune requête refusée n\'a modifié la base');

        return $checked;
    }

    /** @return array<string, int> number of rows of the tables the application can change */
    private function databaseState(): array
    {
        $state = [];
        foreach (['accounts', 'statements', 'users', 'backup_codes', 'one_time_tokens'] as $table) {
            $state[$table] = self::queryInt("SELECT COUNT(*) FROM $table");
        }

        return $state;
    }
}
