<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;

/**
 * Predefined account types (Livret A, PEA, mortgage…) with their default level and nature.
 *
 * @phpstan-type AccountType array{id: int, code: string, label: string, default_level: string, nature: string}
 */
final class AccountTypeRepository
{
    /** @var array<int, string>|null */
    private ?array $labels = null;

    public function __construct(private readonly Connection $connection) {}

    /** Label of a type (reference data read once per request). */
    public function label(int $id): string
    {
        $this->labels ??= array_column($this->all(), 'label', 'id');

        return $this->labels[$id] ?? throw new \UnexpectedValueException("Type de compte inconnu : $id");
    }

    /** @return list<AccountType> sorted by level then by position */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT t.id, t.code, t.label, t.default_level, t.nature
             FROM account_types t JOIN levels l ON l.code = t.default_level
             ORDER BY l.position, t.position',
        );

        return array_map(static fn(array $row): array => [
            'id' => Row::int($row, 'id'),
            'code' => Row::string($row, 'code'),
            'label' => Row::string($row, 'label'),
            'default_level' => Row::string($row, 'default_level'),
            'nature' => Row::string($row, 'nature'),
        ], $rows);
    }
}
