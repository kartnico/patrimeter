<?php

declare(strict_types=1);

namespace Patrimeter\Application\Transfer;

use Patrimeter\Application\ValidationFailed;
use Patrimeter\Domain\Budget\BudgetBook;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Budget\SplitRule;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\BudgetRepository;

/**
 * Household budget in the export file ("patrimeter" format, version 2, docs/export.md): household, categories and
 * their items, amounts of each month. The references of the children, accounts and items belong to the file: the
 * import maps them to the identifiers created, and the data is encrypted again with the key of the importing user.
 * The domain rules validate everything that is read.
 */
final class BudgetTransfer
{
    private const int MAX_CATEGORIES = 200;
    private const int MAX_LINES = 2_000;
    private const int MAX_MONTHS = 1_200;

    public function __construct(private readonly BudgetRepository $budgets) {}

    /** @return array<string, mixed>|null null: no budget */
    public function export(UserId $owner): ?array
    {
        $household = $this->budgets->household($owner);
        if ($household === null) {
            return null;
        }
        $lines = [];
        foreach ($this->budgets->lines($owner) as $line) {
            $lines[$line->categoryId][] = ['ref' => $line->id, 'name' => $line->name, 'child_ref' => $line->childId, 'account_ref' => $line->accountId];
        }
        $children = [];
        foreach ($household->children as $id => $name) {
            $children[] = ['ref' => $id, 'name' => $name, 'birth_year' => $household->childBirthYear($id)];
        }

        return [
            'adults' => array_values($household->adults),
            'adult_birth_years' => array_map($household->adultBirthYear(...), $household->adultIds()),
            'children' => $children,
            'precaution_months' => $household->precautionMonths,
            'categories' => array_map(static fn(BudgetCategory $category): array => [
                'name' => $category->name,
                'group' => $category->group->value,
                'adult' => $category->adult,
                'lines' => $lines[$category->id] ?? [],
            ], $this->budgets->categories($owner)),
            'months' => array_map(static fn(MonthlyBudget $budget): array => [
                'month' => $budget->month->toString(),
                'incomes' => self::pairs($budget->incomes, 'adult'),
                'split_rule' => $budget->splitRule->value,
                'first_adult_share_millionths' => $budget->firstAdultShare,
                'amounts' => self::pairs($budget->amounts, 'line_ref'),
                'child_incomes' => self::pairs($budget->childIncomes, 'child_ref'),
            ], $this->budgets->budgets($owner)),
        ];
    }

    /**
     * @param array<mixed>|null  $budget   "budget" part of the file, null if it has none
     * @param array<string, int> $accounts reference of an account in the file => identifier of the imported account
     *
     * @throws ValidationFailed invalid budget, or account that already has a budget
     */
    public function import(UserId $owner, ?array $budget, array $accounts): void
    {
        if ($budget === null) {
            return;
        }
        if ($this->budgets->household($owner) !== null) {
            throw new ValidationFailed(['file' => 'L\'import se fait dans un compte encore vide : celui-ci a déjà un budget.']);
        }
        $book = self::read($budget, $accounts);
        $this->budgets->saveHousehold($owner, $book->household);
        $categories = [];
        foreach ($book->categories as $category) {
            $categories[$category->id] = $this->budgets->createCategory($owner, $category->name, $category->group, $category->adult);
        }
        $lines = [];
        foreach ($book->lines as $line) {
            $lines[$line->id] = $this->budgets->createLine($owner, $categories[$line->categoryId], $line->name, $line->childId, $line->accountId);
        }
        foreach ($book->months as $month) {
            $amounts = [];
            foreach ($month->amounts as $line => $cents) {
                $amounts[$lines[$line]] = $cents;
            }
            $this->budgets->saveBudget($owner, new MonthlyBudget($month->month, $month->incomes, $amounts, $month->splitRule, $month->firstAdultShare, $month->childIncomes));
        }
    }

    /**
     * Reads and validates the "budget" part of a file, without saving anything: categories and items numbered from
     * $idBase + 1, in the file's order; children numbered from 1. The domain rules validate everything.
     *
     * @param array<mixed>       $budget
     * @param array<string, int> $accounts reference of an account in the file => identifier to give it
     *
     * @throws ValidationFailed unreadable or invalid budget
     */
    public static function read(array $budget, array $accounts, int $idBase = 0): BudgetBook
    {
        try {
            $children = [];
            $childIds = [];
            // Years of birth and incomes of the children: since 2.26.0, optional (absent from an older file).
            $childYears = [];
            foreach (self::rows($budget['children'] ?? [], Household::MAX_CHILDREN) as $index => $child) {
                $children[$index + 1] = self::text($child['name'] ?? null);
                $childIds[self::key($child['ref'] ?? null)] = $index + 1;
                if (($child['birth_year'] ?? null) !== null) {
                    $childYears[$index + 1] = self::int($child['birth_year']);
                }
            }
            $adults = [];
            $adultYears = [];
            $names = self::values($budget['adults'] ?? null);
            $years = self::values($budget['adult_birth_years'] ?? []);
            foreach (array_keys($names) as $index) {
                $adults[$index + 1] = self::text($names[$index]);
                if (($years[$index] ?? null) !== null) {
                    $adultYears[$index + 1] = self::int($years[$index]);
                }
            }
            $household = new Household($adults, $children, self::int($budget['precaution_months'] ?? null), count($children) + 1, $adultYears, $childYears);

            $categories = [];
            $lines = [];
            $lineIds = [];
            foreach (self::rows($budget['categories'] ?? [], self::MAX_CATEGORIES) as $category) {
                $categoryId = $idBase + count($categories) + 1;
                $categories[] = self::category($categoryId, $category, $household);
                $group = $categories[count($categories) - 1]->group;
                foreach (self::rows($category['lines'] ?? [], self::MAX_LINES) as $line) {
                    if (count($lines) >= self::MAX_LINES) {
                        throw new \UnexpectedValueException('Trop de postes.');
                    }
                    $name = self::text($line['name'] ?? null);
                    if (!BudgetCategory::isValidName($name)) {
                        throw new \UnexpectedValueException('Nom de poste invalide.');
                    }
                    $child = ($line['child_ref'] ?? null) === null ? null : self::mapped($childIds, $line['child_ref']);
                    // An account is kept only for a savings item, as in the form.
                    $account = ($line['account_ref'] ?? null) === null || $group !== BudgetGroup::Savings ? null : self::mapped($accounts, $line['account_ref']);
                    $lineId = $idBase + count($lines) + 1;
                    $lines[] = new BudgetLine($lineId, $categoryId, $name, $child, $account);
                    $lineIds[self::key($line['ref'] ?? null)] = $lineId;
                }
            }

            $months = [];
            foreach (self::rows($budget['months'] ?? [], self::MAX_MONTHS) as $month) {
                $incomes = [];
                foreach (self::rows($month['incomes'] ?? [], Household::SECOND_ADULT) as $income) {
                    $adult = self::int($income['adult'] ?? null);
                    if (!$household->hasAdult($adult)) {
                        throw new \UnexpectedValueException('Adulte inconnu.');
                    }
                    $incomes[$adult] = self::int($income['amount_cents'] ?? null);
                }
                $amounts = [];
                foreach (self::rows($month['amounts'] ?? [], self::MAX_LINES) as $amount) {
                    $amounts[self::mapped($lineIds, $amount['line_ref'] ?? null)] = self::int($amount['amount_cents'] ?? null);
                }
                $childIncomes = [];
                foreach (self::rows($month['child_incomes'] ?? [], Household::MAX_CHILDREN) as $income) {
                    $childIncomes[self::mapped($childIds, $income['child_ref'] ?? null)] = self::int($income['amount_cents'] ?? null);
                }
                $months[] = new MonthlyBudget(
                    Month::tryFromString(self::text($month['month'] ?? null)) ?? throw new \UnexpectedValueException('Mois invalide.'),
                    $incomes,
                    $amounts,
                    SplitRule::tryFrom(self::text($month['split_rule'] ?? null)) ?? throw new \UnexpectedValueException('Partage invalide.'),
                    self::int($month['first_adult_share_millionths'] ?? null),
                    $childIncomes,
                );
            }

            return new BudgetBook($household, $categories, $lines, $months);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            throw new ValidationFailed(['file' => 'Budget du foyer illisible ou invalide.']);
        }
    }

    /** @param array<mixed> $category */
    private static function category(int $id, array $category, Household $household): BudgetCategory
    {
        $name = self::text($category['name'] ?? null);
        $adult = $category['adult'] ?? null;
        $group = BudgetGroup::tryFrom(self::text($category['group'] ?? null));
        if (!BudgetCategory::isValidName($name) || $group === null || ($adult !== null && (!is_int($adult) || !$household->hasAdult($adult)))) {
            throw new \UnexpectedValueException('Catégorie invalide.');
        }

        return new BudgetCategory($id, $name, $group, $adult);
    }

    /**
     * @param array<int, int> $values
     *
     * @return list<array<string, int>>
     */
    private static function pairs(array $values, string $key): array
    {
        $pairs = [];
        foreach ($values as $id => $cents) {
            $pairs[] = [$key => $id, 'amount_cents' => $cents];
        }

        return $pairs;
    }

    /** @return array<mixed> */
    private static function record(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Objet attendu.');
        }

        return $value;
    }

    /** @return list<mixed> */
    private static function values(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new \UnexpectedValueException('Liste attendue.');
        }

        return $value;
    }

    /** @return list<array<mixed>> */
    private static function rows(mixed $value, int $max): array
    {
        $values = self::values($value);
        if (count($values) > $max) {
            throw new \UnexpectedValueException('Liste trop longue.');
        }

        return array_map(self::record(...), $values);
    }

    /** @param array<string, int> $map */
    private static function mapped(array $map, mixed $ref): int
    {
        return $map[self::key($ref)] ?? throw new \UnexpectedValueException('Référence inconnue.');
    }

    private static function key(mixed $ref): string
    {
        return is_int($ref) || is_string($ref) ? (string) $ref : '';
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function int(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \UnexpectedValueException('Entier attendu.');
        }

        return $value;
    }
}
