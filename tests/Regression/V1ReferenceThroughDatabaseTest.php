<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Regression;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\Row;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Patrimeter\Tests\Support\IntegrationTestCase;
use Patrimeter\Tests\Support\ReferenceDataset;

/**
 * The reference dataset saved by the repositories then read back from SQLite gives exactly the results of v1: covers
 * the writing, the reading back and the sorting of the accounts.
 */
final class V1ReferenceThroughDatabaseTest extends IntegrationTestCase
{
    public function testEveryMonthMatchesV1AfterARoundTripThroughTheDatabase(): void
    {
        self::bootKernel();
        $ids = self::storeDataset();

        $calculator = new NetWorthCalculator(
            self::service(AccountRepository::class)->all(self::owner()),
            self::service(StatementRepository::class)->valuation(self::owner()),
        );
        foreach (ReferenceDataset::expected() as $expected) {
            $actual = V1ReferenceTest::summary($calculator->snapshot(Month::fromString($expected['month'])));
            self::assertSame(self::withDatasetIds($expected, $ids), $actual, $expected['month']);
        }
    }

    /** @return array<int, int> identifier in the reference dataset => identifier in the database */
    private static function storeDataset(): array
    {
        $typeIds = [];
        foreach (self::service(Connection::class)->fetchAllAssociative('SELECT id, code FROM account_types') as $row) {
            $typeIds[Row::string($row, 'code')] = Row::int($row, 'id');
        }

        $accounts = self::service(AccountRepository::class);
        $ids = [];
        foreach (ReferenceDataset::accounts() as $account) {
            $id = $accounts->create(self::owner(), [
                'name' => $account['name'], 'type_id' => $typeIds[$account['type']], 'level_code' => $account['level'],
                'nature' => $account['nature'], 'opened_on' => $account['openedOn'],
            ]);
            if ($account['closedOn'] !== null) {
                $accounts->archive(self::owner(), $id, $account['closedOn']);
            }
            $ids[$account['id']] = $id;
        }

        $statements = self::service(StatementRepository::class);
        foreach (ReferenceDataset::statements() as $statement) {
            $statements->save(self::owner(), $ids[$statement['account']], Month::fromString($statement['month']), $statement['cents']);
        }

        return $ids;
    }

    /**
     * Expected result, with the identifiers given by the database. Within a level, the rows follow the order of the
     * accounts returned by the repository (nature, then name regardless of ASCII case, like COLLATE NOCASE): they are
     * sorted the same way.
     *
     * @param array<string, mixed> $expected
     * @param array<int, int>      $ids identifier in the reference dataset => identifier in the database
     *
     * @return array<string, mixed>
     */
    private static function withDatasetIds(array $expected, array $ids): array
    {
        $accounts = [];
        foreach (ReferenceDataset::accounts() as $account) {
            $accounts[$account['id']] = $account;
        }
        $sortKey = static fn(int $datasetId): string => $accounts[$datasetId]['nature'] . '|' . strtolower($accounts[$datasetId]['name']);

        /** @var array<string, array{lines: list<array{account: int}>}> $levels */
        $levels = $expected['levels'];
        foreach ($levels as $code => $level) {
            $lines = $level['lines'];
            usort($lines, static fn(array $a, array $b): int => $sortKey($a['account']) <=> $sortKey($b['account']));
            $levels[$code]['lines'] = array_map(static fn(array $line): array => ['account' => $ids[$line['account']]] + $line, $lines);
        }
        $expected['levels'] = $levels;

        return $expected;
    }
}
