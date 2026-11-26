<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\ApiTestCase;
use Patrimeter\Tests\Support\RouteCatalog;
use Patrimeter\Tests\Support\TestUsers;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/** Isolation robot of the API: an intruder's token sees and changes nothing of its victim's. */
final class ApiIsolationTest extends ApiTestCase
{
    private const string SECRET = 'Compte-De-La-Victime';

    public function testEveryRouteWithAnAccountIdFindsNothingOfAnotherUser(): void
    {
        $victim = self::createAccount(self::SECRET, 'lmnp');
        self::service(StatementRepository::class)->save(self::owner(), $victim, Month::fromString('2026-01'), 123_456);
        self::service(OwnershipShareRepository::class)->save(self::owner(), $victim, Month::fromString('2026-01'), 500_000);
        $intruder = TestUsers::create(static::getContainer(), TestUsers::OTHER_EMAIL);
        $token = $this->personalToken(write: true, owner: $intruder);
        $before = self::service(Connection::class)->fetchAllAssociative('SELECT ciphertext FROM accounts UNION ALL SELECT ciphertext FROM statements UNION ALL SELECT ciphertext FROM ownership_shares');

        $exercised = [];
        foreach (RouteCatalog::routes(self::service(RouterInterface::class)) as $name => $route) {
            if (!in_array($name, RouteCatalog::BEARER, true)) {
                continue;
            }
            $path = RouteCatalog::path($route, ['id' => (string) $victim, 'month' => '2026-01']);
            foreach ($route->getMethods() as $method) {
                $response = $this->api($method, $path, $token, $method === 'GET' ? null : ['amount_cents' => 1, 'share_millionths' => 1, 'name' => 'Intrus', 'type_id' => 1, 'opened_on' => '2025-01-01', 'closed_on' => '2026-01-01', 'asset_id' => $victim]);
                self::assertStringNotContainsString(self::SECRET, (string) $response->getContent(), "$method $path");
                if (str_contains($route->getPath(), '{id}')) {
                    self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode(), "$method $path");
                }
                $exercised[] = $name;
            }
        }

        self::assertSame([], array_diff(RouteCatalog::BEARER, $exercised), 'toutes les routes sont exercées');
        $rows = self::service(Connection::class)->fetchAllAssociative('SELECT ciphertext FROM accounts WHERE user_id = ? UNION ALL SELECT ciphertext FROM statements WHERE user_id = ? UNION ALL SELECT ciphertext FROM ownership_shares WHERE user_id = ?', array_fill(0, 3, self::owner()->value));
        self::assertSame(array_slice($before, 0, 1), array_slice($rows, 0, 1), 'compte de la victime intact');
        self::assertCount(3, $rows, 'compte, relevé et part de la victime toujours là');
    }

    public function testALoanCannotBeLinkedToAnotherUsersProperty(): void
    {
        $victimHouse = self::createAccount('Maison de la victime', 'lmnp');
        $intruder = TestUsers::create(static::getContainer(), TestUsers::OTHER_EMAIL);
        $loan = self::createAccount('Prêt de l\'intrus', 'credit_immo_rp', owner: $intruder);
        $token = $this->personalToken(write: true, owner: $intruder);

        $this->api('PUT', "/api/v1/accounts/$loan/loan-link", $token, ['asset_id' => $victimHouse]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
