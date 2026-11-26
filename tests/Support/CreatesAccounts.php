<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Support;

use Doctrine\DBAL\Connection;
use Patrimeter\Application\Family\FamilyPortfolio;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;
use Patrimeter\Infrastructure\Persistence\Row;

/**
 * Test data: default owner (TestUsers::EMAIL, created at first need in the test's transaction), accounts created from
 * the predefined types and minimal budget.
 */
trait CreatesAccounts
{
    /** Owner of the test data. */
    protected static function owner(): UserId
    {
        return TestUsers::findOrCreate(static::getContainer());
    }

    /**
     * Integer value of a counting query (first field of the first row).
     *
     * @param list<mixed> $parameters
     */
    protected static function queryInt(string $sql, array $parameters = []): int
    {
        $row = self::service(Connection::class)->fetchNumeric($sql, $parameters);
        self::assertIsArray($row);
        self::assertIsInt($row[0] ?? null, "Comptage attendu : $sql");

        return $row[0];
    }

    protected static function createAccount(string $name, string $typeCode, string $openedOn = '2025-01-01', ?UserId $owner = null): int
    {
        $type = self::service(Connection::class)->fetchAssociative(
            'SELECT id, default_level, nature FROM account_types WHERE code = ?',
            [$typeCode],
        );
        self::assertIsArray($type, "Type de compte inconnu : $typeCode");

        $owner ??= self::owner();
        TestUsers::unlock(static::getContainer(), $owner);

        return self::service(AccountRepository::class)->create($owner, [
            'name' => $name,
            'type_id' => Row::int($type, 'id'),
            'level_code' => Row::string($type, 'default_level'),
            'nature' => Row::string($type, 'nature'),
            'opened_on' => $openedOn,
        ]);
    }

    /**
     * Minimal budget: one adult, one shared category (needs) with one item, and their amounts for the month.
     *
     * @return array{category: int, line: int}
     */
    protected static function createBudget(UserId $owner, string $adult, string $category, string $line, int $cents, string $month = '2026-10'): array
    {
        TestUsers::unlock(static::getContainer(), $owner);
        $budgets = self::service(BudgetRepository::class);
        $budgets->saveHousehold($owner, new Household([Household::FIRST_ADULT => $adult]));
        $categoryId = $budgets->createCategory($owner, $category, BudgetGroup::Needs, null);
        $lineId = $budgets->createLine($owner, $categoryId, $line);
        $budgets->saveBudget($owner, new MonthlyBudget(Month::fromString($month), [Household::FIRST_ADULT => 2 * $cents], [$lineId => $cents]));

        return ['category' => $categoryId, 'line' => $lineId];
    }

    /**
     * Member of a family account: an export file (a savings account with the given balance in October 2026 and, with
     * $income, a one-adult budget), imported for $owner.
     *
     * @return int identifier of the member
     */
    protected static function createFamilyMember(UserId $owner, string $name, string $accountName, int $cents, ?int $income = null): int
    {
        TestUsers::unlock(static::getContainer(), $owner);

        return self::service(FamilyPortfolio::class)->import($owner, null, $name, json_encode(self::memberExport($accountName, $cents, $income), JSON_THROW_ON_ERROR))->id;
    }

    /**
     * Minimal export file ("patrimeter" format, version 2): a savings account opened in 2024, its balance
     * ($statementMonth) and, with $income, a one-adult budget (incomes, a €1,000 housing item, a savings item towards
     * the savings account).
     *
     * @return array<string, mixed>
     */
    protected static function memberExport(string $accountName, int $cents, ?int $income = null, string $adult = 'Alex', string $statementMonth = '2026-10'): array
    {
        return [
            'format' => 'patrimeter',
            'version' => 2,
            'exported_on' => '2026-11-01',
            'accounts' => [[
                'ref' => 1, 'name' => $accountName, 'type' => 'livret_a', 'level' => 'precaution', 'nature' => 'asset',
                'opened_on' => '2024-01-01', 'closed_on' => null, 'linked_asset_ref' => null, 'loan_mode' => 'prorata',
                'statements' => [['month' => $statementMonth, 'amount_cents' => $cents]], 'shares' => [],
            ]],
            'budget' => $income === null ? null : [
                'adults' => [$adult],
                'children' => [['ref' => 1, 'name' => 'Léa']],
                'precaution_months' => 3,
                'categories' => [
                    ['name' => 'Logement', 'group' => 'needs', 'adult' => null, 'lines' => [['ref' => 10, 'name' => 'Loyer', 'child_ref' => null, 'account_ref' => null]]],
                    ['name' => 'Épargne', 'group' => 'savings', 'adult' => null, 'lines' => [['ref' => 11, 'name' => 'Livret', 'child_ref' => 1, 'account_ref' => 1]]],
                ],
                'months' => [[
                    'month' => '2026-10', 'incomes' => [['adult' => 1, 'amount_cents' => $income]], 'split_rule' => 'income',
                    'first_adult_share_millionths' => 500_000,
                    'amounts' => [['line_ref' => 10, 'amount_cents' => 100_000], ['line_ref' => 11, 'amount_cents' => 20_000]],
                ]],
            ],
        ];
    }
}
