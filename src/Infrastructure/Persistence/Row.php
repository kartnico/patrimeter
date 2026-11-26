<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

/**
 * Typed reading of a row returned by the database.
 *
 * SQLite returns strings or integers depending on the driver: these accessors convert explicitly and fail loudly
 * if an expected column is missing or has an unexpected type.
 */
final class Row
{
    /** @param array<string, mixed> $row */
    public static function int(array $row, string $column): int
    {
        self::assertPresent($row, $column);
        if (is_int($row[$column])) {
            return $row[$column];
        }
        if (is_string($row[$column]) && preg_match('/\A-?\d+\z/', $row[$column]) === 1) {
            return (int) $row[$column];
        }

        throw new \UnexpectedValueException(sprintf('Colonne « %s » : entier attendu.', $column));
    }

    /** @param array<string, mixed> $row */
    public static function string(array $row, string $column): string
    {
        self::assertPresent($row, $column);
        if (is_string($row[$column])) {
            return $row[$column];
        }
        if (is_int($row[$column])) {
            return (string) $row[$column];
        }

        throw new \UnexpectedValueException(sprintf('Colonne « %s » : texte attendu.', $column));
    }

    /** @param array<string, mixed> $row */
    public static function nullableString(array $row, string $column): ?string
    {
        self::assertPresent($row, $column);

        return $row[$column] === null ? null : self::string($row, $column);
    }

    /** @param array<string, mixed> $row */
    public static function nullableInt(array $row, string $column): ?int
    {
        self::assertPresent($row, $column);

        return $row[$column] === null ? null : self::int($row, $column);
    }

    /** @param array<string, mixed> $row */
    private static function assertPresent(array $row, string $column): void
    {
        if (!array_key_exists($column, $row)) {
            throw new \UnexpectedValueException(sprintf('Colonne « %s » absente.', $column));
        }
    }
}
