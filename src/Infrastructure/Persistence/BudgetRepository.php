<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Budget\SplitRule;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Crypto\Aead;
use Patrimeter\Security\Vault\Vault;

/**
 * Monthly budget of a user: their household, categories, items and the amounts of each month, encrypted with their
 * data key (sealed JSON, tied to its row and to its owner). The amounts of a month apply to the following months until
 * the next save (budgetAt).
 *
 * Isolation: each query filters or writes `user_id` (tests/Architecture/OwnedDataQueriesTest), and the database
 * refuses an item filed in another user's category (composite foreign key).
 */
final class BudgetRepository
{
    private const string PLACEHOLDER = 'en-cours';

    public function __construct(
        private readonly Connection $connection,
        private readonly Vault $vault,
    ) {}

    public function household(UserId $owner): ?Household
    {
        $row = $this->connection->fetchAssociative('SELECT ciphertext FROM budget_households WHERE user_id = ?', [$owner->value]);
        if ($row === false) {
            return null;
        }
        $data = $this->open($owner, Row::string($row, 'ciphertext'), self::householdContext($owner));

        $adults = self::names($data['adults'] ?? null);
        $children = self::names($data['children'] ?? null);

        // Years of birth: since 2.26.0, absent from a household saved before.
        return new Household(
            $adults,
            $children,
            Row::int($data, 'precaution_months'),
            Row::int($data, 'next_child_id'),
            array_intersect_key(self::cents($data['adult_birth_years'] ?? null), $adults),
            array_intersect_key(self::cents($data['child_birth_years'] ?? null), $children),
        );
    }

    public function saveHousehold(UserId $owner, Household $household): void
    {
        $sealed = $this->seal($owner, [
            'adults' => $household->adults,
            'children' => $household->children,
            'precaution_months' => $household->precautionMonths,
            'next_child_id' => $household->nextChildId,
            'adult_birth_years' => $household->adultBirthYears,
            'child_birth_years' => $household->childBirthYears,
        ], self::householdContext($owner));
        $this->connection->executeStatement(
            "INSERT INTO budget_households (user_id, ciphertext) VALUES (?, ?)
             ON CONFLICT(user_id) DO UPDATE SET ciphertext = excluded.ciphertext,
                 updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')",
            [$owner->value, $sealed],
        );
    }

    /** @return list<BudgetCategory> in the order chosen by the user (order of creation by default) */
    public function categories(UserId $owner): array
    {
        return array_map(
            fn(array $row): BudgetCategory => $this->category($owner, $row),
            $this->connection->fetchAllAssociative('SELECT id, ciphertext FROM budget_categories WHERE user_id = ? ORDER BY position, id', [$owner->value]),
        );
    }

    public function findCategory(UserId $owner, int $id): ?BudgetCategory
    {
        $row = $this->connection->fetchAssociative('SELECT id, ciphertext FROM budget_categories WHERE user_id = ? AND id = ?', [$owner->value, $id]);

        return $row === false ? null : $this->category($owner, $row);
    }

    public function createCategory(UserId $owner, string $name, BudgetGroup $group, ?int $adult): int
    {
        return $this->connection->transactional(function () use ($owner, $name, $group, $adult): int {
            // The identifier is part of the ciphertext's context: the row is reserved first.
            $this->connection->executeStatement(
                'INSERT INTO budget_categories (user_id, ciphertext, position) VALUES (?, ?, (SELECT COALESCE(MAX(position), 0) + 1 FROM budget_categories WHERE user_id = ?))',
                [$owner->value, self::PLACEHOLDER, $owner->value],
            );
            $id = (int) $this->connection->lastInsertId();
            $this->storeCategory($owner, new BudgetCategory($id, $name, $group, $adult));

            return $id;
        });
    }

    public function updateCategory(UserId $owner, BudgetCategory $category): void
    {
        if ($this->findCategory($owner, $category->id) !== null) {
            $this->storeCategory($owner, $category);
        }
    }

    /**
     * New order of the categories: positions 1, 2… in the order of $ids (identifiers of other users are ignored).
     *
     * @param list<int> $ids
     */
    public function reorderCategories(UserId $owner, array $ids): void
    {
        foreach ($ids as $index => $id) {
            $this->connection->executeStatement('UPDATE budget_categories SET position = ? WHERE id = ? AND user_id = ?', [$index + 1, $id, $owner->value]);
        }
    }

    /**
     * New order of the items: positions 1, 2… in the order of $ids (identifiers of other users are ignored).
     *
     * @param list<int> $ids
     */
    public function reorderLines(UserId $owner, array $ids): void
    {
        foreach ($ids as $index => $id) {
            $this->connection->executeStatement('UPDATE budget_lines SET position = ? WHERE id = ? AND user_id = ?', [$index + 1, $id, $owner->value]);
        }
    }

    /** Deletes the category and, by cascade, its items. */
    public function deleteCategory(UserId $owner, int $id): void
    {
        $this->connection->executeStatement('DELETE FROM budget_categories WHERE id = ? AND user_id = ?', [$id, $owner->value]);
    }

    /** @return list<BudgetLine> in the order chosen by the user (order of creation by default) */
    public function lines(UserId $owner): array
    {
        return array_map(
            fn(array $row): BudgetLine => $this->line($owner, $row),
            $this->connection->fetchAllAssociative('SELECT id, category_id, ciphertext FROM budget_lines WHERE user_id = ? ORDER BY position, id', [$owner->value]),
        );
    }

    public function findLine(UserId $owner, int $id): ?BudgetLine
    {
        $row = $this->connection->fetchAssociative('SELECT id, category_id, ciphertext FROM budget_lines WHERE user_id = ? AND id = ?', [$owner->value, $id]);

        return $row === false ? null : $this->line($owner, $row);
    }

    public function createLine(UserId $owner, int $categoryId, string $name, ?int $childId = null, ?int $accountId = null): int
    {
        return $this->connection->transactional(function () use ($owner, $categoryId, $name, $childId, $accountId): int {
            $this->connection->executeStatement(
                'INSERT INTO budget_lines (user_id, category_id, ciphertext, position) VALUES (?, ?, ?, (SELECT COALESCE(MAX(position), 0) + 1 FROM budget_lines WHERE user_id = ?))',
                [$owner->value, $categoryId, self::PLACEHOLDER, $owner->value],
            );
            $id = (int) $this->connection->lastInsertId();
            $this->storeLine($owner, new BudgetLine($id, $categoryId, $name, $childId, $accountId));

            return $id;
        });
    }

    /** Saves the item, possibly filed in another category of the same user. */
    public function updateLine(UserId $owner, BudgetLine $line): void
    {
        if ($this->findLine($owner, $line->id) !== null) {
            $this->storeLine($owner, $line);
        }
    }

    public function deleteLine(UserId $owner, int $id): void
    {
        $this->connection->executeStatement('DELETE FROM budget_lines WHERE id = ? AND user_id = ?', [$id, $owner->value]);
    }

    /** Amounts in force for the month: those of the month if they exist, otherwise the last ones saved before it. */
    public function budgetAt(UserId $owner, Month $month): ?MonthlyBudget
    {
        $row = $this->connection->fetchAssociative(
            'SELECT month, ciphertext FROM budget_months WHERE user_id = ? AND month <= ? ORDER BY month DESC LIMIT 1',
            [$owner->value, $month->toString()],
        );

        return $row === false ? null : $this->monthlyBudget($owner, $row);
    }

    /** @return list<MonthlyBudget> all the months saved, from the oldest to the most recent */
    public function budgets(UserId $owner): array
    {
        return array_map(
            fn(array $row): MonthlyBudget => $this->monthlyBudget($owner, $row),
            $this->connection->fetchAllAssociative('SELECT month, ciphertext FROM budget_months WHERE user_id = ? ORDER BY month', [$owner->value]),
        );
    }

    /** @return list<Month> months with a budget saved, ascending (nothing decrypted) */
    public function months(UserId $owner): array
    {
        return array_map(
            static fn(array $row): Month => Month::fromString(Row::string($row, 'month')),
            $this->connection->fetchAllAssociative('SELECT month FROM budget_months WHERE user_id = ? ORDER BY month', [$owner->value]),
        );
    }

    /** First month saved, or null. */
    public function firstMonth(UserId $owner): ?Month
    {
        $row = $this->connection->fetchAssociative('SELECT MIN(month) AS month FROM budget_months WHERE user_id = ?', [$owner->value]);
        $month = $row === false ? null : Row::nullableString($row, 'month');

        return $month === null ? null : Month::fromString($month);
    }

    public function saveBudget(UserId $owner, MonthlyBudget $budget): void
    {
        $month = $budget->month->toString();
        $sealed = $this->seal($owner, [
            'incomes' => $budget->incomes,
            'amounts' => $budget->amounts,
            'split_rule' => $budget->splitRule->value,
            'first_adult_share' => $budget->firstAdultShare,
            'child_incomes' => $budget->childIncomes,
        ], self::monthContext($owner, $month));
        $this->connection->executeStatement(
            "INSERT INTO budget_months (user_id, month, ciphertext) VALUES (?, ?, ?)
             ON CONFLICT(user_id, month) DO UPDATE SET ciphertext = excluded.ciphertext,
                 updated_at = strftime('%Y-%m-%dT%H:%M:%SZ', 'now')",
            [$owner->value, $month, $sealed],
        );
    }

    public function deleteBudget(UserId $owner, Month $month): void
    {
        $this->connection->executeStatement('DELETE FROM budget_months WHERE user_id = ? AND month = ?', [$owner->value, $month->toString()]);
    }

    /** Encrypts the owner's whole budget again: the current key is already open, $newKey replaces it. */
    public function reencryptAll(UserId $owner, #[\SensitiveParameter] string $newKey): void
    {
        $key = $this->vault->key($owner);
        $household = $this->connection->fetchAssociative('SELECT ciphertext FROM budget_households WHERE user_id = ?', [$owner->value]);
        if ($household !== false) {
            $context = self::householdContext($owner);
            $this->connection->executeStatement(
                'UPDATE budget_households SET ciphertext = ? WHERE user_id = ?',
                [Aead::seal($newKey, Aead::open($key, Row::string($household, 'ciphertext'), $context), $context), $owner->value],
            );
        }
        foreach ($this->connection->fetchAllAssociative('SELECT id, ciphertext FROM budget_categories WHERE user_id = ?', [$owner->value]) as $row) {
            $id = Row::int($row, 'id');
            $context = self::categoryContext($owner, $id);
            $this->connection->executeStatement(
                'UPDATE budget_categories SET ciphertext = ? WHERE id = ? AND user_id = ?',
                [Aead::seal($newKey, Aead::open($key, Row::string($row, 'ciphertext'), $context), $context), $id, $owner->value],
            );
        }
        foreach ($this->connection->fetchAllAssociative('SELECT id, category_id, ciphertext FROM budget_lines WHERE user_id = ?', [$owner->value]) as $row) {
            $id = Row::int($row, 'id');
            $context = self::lineContext($owner, $id, Row::int($row, 'category_id'));
            $this->connection->executeStatement(
                'UPDATE budget_lines SET ciphertext = ? WHERE id = ? AND user_id = ?',
                [Aead::seal($newKey, Aead::open($key, Row::string($row, 'ciphertext'), $context), $context), $id, $owner->value],
            );
        }
        foreach ($this->connection->fetchAllAssociative('SELECT month, ciphertext FROM budget_months WHERE user_id = ?', [$owner->value]) as $row) {
            $month = Row::string($row, 'month');
            $context = self::monthContext($owner, $month);
            $this->connection->executeStatement(
                'UPDATE budget_months SET ciphertext = ? WHERE user_id = ? AND month = ?',
                [Aead::seal($newKey, Aead::open($key, Row::string($row, 'ciphertext'), $context), $context), $owner->value, $month],
            );
        }
    }

    private function storeCategory(UserId $owner, BudgetCategory $category): void
    {
        $sealed = $this->seal($owner, ['name' => $category->name, 'group' => $category->group->value, 'adult' => $category->adult], self::categoryContext($owner, $category->id));
        $this->connection->executeStatement('UPDATE budget_categories SET ciphertext = ? WHERE id = ? AND user_id = ?', [$sealed, $category->id, $owner->value]);
    }

    private function storeLine(UserId $owner, BudgetLine $line): void
    {
        $sealed = $this->seal(
            $owner,
            ['name' => $line->name, 'child' => $line->childId, 'account' => $line->accountId],
            self::lineContext($owner, $line->id, $line->categoryId),
        );
        $this->connection->executeStatement(
            'UPDATE budget_lines SET category_id = ?, ciphertext = ? WHERE id = ? AND user_id = ?',
            [$line->categoryId, $sealed, $line->id, $owner->value],
        );
    }

    /** @param array<string, mixed> $row */
    private function category(UserId $owner, array $row): BudgetCategory
    {
        $id = Row::int($row, 'id');
        $data = $this->open($owner, Row::string($row, 'ciphertext'), self::categoryContext($owner, $id));

        return new BudgetCategory($id, Row::string($data, 'name'), BudgetGroup::from(Row::string($data, 'group')), self::nullableInt($data['adult'] ?? null));
    }

    /** @param array<string, mixed> $row */
    private function line(UserId $owner, array $row): BudgetLine
    {
        $id = Row::int($row, 'id');
        $categoryId = Row::int($row, 'category_id');
        $data = $this->open($owner, Row::string($row, 'ciphertext'), self::lineContext($owner, $id, $categoryId));

        return new BudgetLine($id, $categoryId, Row::string($data, 'name'), self::nullableInt($data['child'] ?? null), self::nullableInt($data['account'] ?? null));
    }

    /** @param array<string, mixed> $row */
    private function monthlyBudget(UserId $owner, array $row): MonthlyBudget
    {
        $month = Row::string($row, 'month');
        $data = $this->open($owner, Row::string($row, 'ciphertext'), self::monthContext($owner, $month));

        return new MonthlyBudget(
            Month::fromString($month),
            self::cents($data['incomes'] ?? null),
            self::cents($data['amounts'] ?? null),
            SplitRule::tryFrom(Row::string($data, 'split_rule')) ?? SplitRule::Income,
            Row::int($data, 'first_adult_share'),
            self::cents($data['child_incomes'] ?? null),
        );
    }

    /** @param array<string, mixed> $data */
    private function seal(UserId $owner, array $data, string $context): string
    {
        // JSON object even when empty or with consecutive keys: reading it back finds numeric keys.
        return Aead::seal($this->vault->key($owner), json_encode($data, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT), $context);
    }

    /** @return array<string, mixed> */
    private function open(UserId $owner, string $ciphertext, string $context): array
    {
        $data = json_decode(Aead::open($this->vault->key($owner), $ciphertext, $context), true, 8, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \UnexpectedValueException('Budget déchiffré invalide.');
        }
        /** @var array<string, mixed> $data */
        return $data;
    }

    /** @return array<int, string> */
    private static function names(mixed $value): array
    {
        $names = [];
        $values = is_array($value) ? $value : [];
        foreach (array_keys($values) as $key) {
            if (is_string($values[$key])) {
                $names[(int) $key] = $values[$key];
            }
        }

        return $names;
    }

    /** @return array<int, int> */
    private static function cents(mixed $value): array
    {
        $cents = [];
        $values = is_array($value) ? $value : [];
        foreach (array_keys($values) as $key) {
            if (is_int($values[$key])) {
                $cents[(int) $key] = $values[$key];
            }
        }

        return $cents;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private static function householdContext(UserId $owner): string
    {
        return "budget_households:{$owner->value}:1";
    }

    private static function categoryContext(UserId $owner, int $id): string
    {
        return "budget_categories:$id:{$owner->value}:1";
    }

    private static function lineContext(UserId $owner, int $id, int $categoryId): string
    {
        return "budget_lines:$id:$categoryId:{$owner->value}:1";
    }

    private static function monthContext(UserId $owner, string $month): string
    {
        return "budget_months:$month:{$owner->value}:1";
    }
}
