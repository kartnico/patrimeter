<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

/**
 * Frozen v1 reference dataset (tests/Fixtures/regression): data and expected results.
 *
 * @phpstan-type DatasetAccount array{id: int, name: string, type: string, level: string, nature: string, openedOn: string, closedOn: string|null}
 * @phpstan-type DatasetStatement array{account: int, month: string, cents: int}
 * @phpstan-type ExpectedLine array{account: int, cents: int, sourceMonth: string, carried: bool}
 * @phpstan-type ExpectedLevel array{assets: int, liabilities: int, net: int, share: float|int|null, lines: list<ExpectedLine>}
 * @phpstan-type ExpectedMonth array{month: string, hasData: bool, net: int, assets: int, liabilities: int, enteredCount: int, carriedCount: int, levels: array<string, ExpectedLevel>}
 */
final class ReferenceDataset
{
    public const string DIRECTORY = __DIR__ . '/../Fixtures/regression';

    /** @return list<DatasetAccount> */
    public static function accounts(): array
    {
        /** @var array{accounts: list<DatasetAccount>} $dataset */
        $dataset = self::json('v1-dataset.json');

        return $dataset['accounts'];
    }

    /** @return list<DatasetStatement> */
    public static function statements(): array
    {
        /** @var array{statements: list<DatasetStatement>} $dataset */
        $dataset = self::json('v1-dataset.json');

        return $dataset['statements'];
    }

    /** @return list<ExpectedMonth> */
    public static function expected(): array
    {
        /** @var list<ExpectedMonth> $expected */
        $expected = self::json('v1-expected.json');

        return $expected;
    }

    public static function pyramid(string $month): string
    {
        $svg = file_get_contents(self::DIRECTORY . "/v1-pyramid-$month.svg");
        if ($svg === false) {
            throw new \RuntimeException("Pyramide de référence absente : $month");
        }

        return rtrim($svg, "\n");
    }

    private static function json(string $file): mixed
    {
        $content = file_get_contents(self::DIRECTORY . '/' . $file);
        if ($content === false) {
            throw new \RuntimeException("Fichier de référence absent : $file");
        }

        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
