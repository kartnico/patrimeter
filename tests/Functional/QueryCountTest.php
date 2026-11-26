<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Functional;

use Patrimeter\Domain\Month;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\FunctionalTestCase;
use Patrimeter\Tests\Support\QueryCounter;
use PHPUnit\Framework\Attributes\DataProvider;

/** No N+1 queries: the number of queries of a page does not depend on the number of accounts. */
final class QueryCountTest extends FunctionalTestCase
{
    /** @return iterable<string, array{string}> */
    public static function pages(): iterable
    {
        yield 'accueil' => ['/'];
        yield 'comptes' => ['/comptes'];
        yield 'saisie' => ['/saisie'];
        yield 'patrimoine et tout son historique' => ['/?periode=tout&vue=niveaux'];
        yield 'biens' => ['/biens'];
    }

    #[DataProvider('pages')]
    public function testQueryCountDoesNotDependOnTheNumberOfAccounts(string $page): void
    {
        $this->logIn();
        $this->addAccounts(2);
        $few = $this->queriesOf($page);
        self::assertGreaterThan(0, $few, 'le compteur voit bien les requêtes');

        $this->addAccounts(15);
        $many = $this->queriesOf($page);

        self::assertSame($few, $many, "$page : le nombre de requêtes augmente avec le nombre de comptes");
    }

    private function addAccounts(int $count): void
    {
        $statements = self::service(StatementRepository::class);
        for ($i = 0; $i < $count; ++$i) {
            $id = self::createAccount("Compte $i", $i % 2 === 0 ? 'livret_a' : 'lmnp', '2024-01-01');
            $statements->save(self::owner(), $id, Month::fromString('2026-10'), 1_000 + $i);
        }
    }

    private function queriesOf(string $page): int
    {
        // A first visit evens out the measurements (settings at the SQLite connection, session).
        $this->client->request('GET', $page);
        $before = QueryCounter::$count;
        $this->client->request('GET', $page);
        self::assertResponseIsSuccessful();

        return QueryCounter::$count - $before;
    }
}
