<?php

declare(strict_types=1);

namespace Patrimeter\Application\Form;

use Patrimeter\Domain\Budget\Household;
use Patrimeter\Domain\Budget\JointAccount;
use Patrimeter\Domain\Budget\MonthlyBudget;
use Patrimeter\Domain\Budget\SplitRule;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Budget amounts of a month: each adult's net incomes, split of the joint account and amount of each item, read in
 * the page's language. An empty field is worth 0. All or nothing: a single invalid amount and nothing is saved.
 */
final class BudgetAmountsForm
{
    /** @var array<string, TranslatableInterface> errors by field (incomes.1, amounts.12, first_share) */
    public array $errors = [];
    public ?MonthlyBudget $budget = null;

    /**
     * @param array<int, string> $incomes adult => input
     * @param array<int, string> $amounts item => input
     * @param list<int>          $lineIds the user's items (other inputs are ignored)
     * @param array<int, string> $childIncomes child => input (children of working age in this month; others ignored)
     */
    private function __construct(
        public readonly Month $month,
        public readonly array $incomes,
        public readonly array $amounts,
        public readonly string $splitRule,
        public readonly string $firstShare,
        Household $household,
        array $lineIds,
        string $locale,
        public readonly array $childIncomes = [],
    ) {
        $incomeCents = [];
        foreach ($household->adultIds() as $adult) {
            $cents = $this->cents($incomes[$adult] ?? '', "incomes.$adult", $locale);
            if ($cents > 0) {
                $incomeCents[$adult] = $cents;
            }
        }
        $childCents = [];
        foreach (array_keys($household->workingChildren($month)) as $child) {
            $cents = $this->cents($childIncomes[$child] ?? '', "child_incomes.$child", $locale);
            if ($cents > 0) {
                $childCents[$child] = $cents;
            }
        }
        $amountCents = [];
        foreach ($lineIds as $id) {
            $cents = $this->cents($amounts[$id] ?? '', "amounts.$id", $locale);
            if ($cents > 0) {
                $amountCents[$id] = $cents;
            }
        }
        $rule = $household->isCouple() ? (SplitRule::tryFrom($splitRule) ?? SplitRule::Income) : SplitRule::Income;
        // Fixed share kept even when split by incomes: it comes back when switching to a fixed share again.
        $share = JointAccount::parseShare($firstShare);
        if ($share === null && $rule === SplitRule::Fixed) {
            $this->errors['first_share'] = t('Indiquez un pourcentage de 0 à 100.');
        }
        if ($this->errors === []) {
            $this->budget = new MonthlyBudget($month, $incomeCents, $amountCents, $rule, $share ?? JointAccount::HALF, $childCents);
        }
    }

    /**
     * @param array<mixed> $incomes inputs, by adult
     * @param array<mixed> $amounts inputs, by item
     * @param list<int>    $lineIds
     * @param string       $locale       language of the page, to read the amounts
     * @param array<mixed> $childIncomes inputs, by child of working age
     */
    public static function validate(Month $month, array $incomes, array $amounts, string $splitRule, string $firstShare, Household $household, array $lineIds, string $locale = 'fr', array $childIncomes = []): self
    {
        return new self($month, self::texts($incomes), self::texts($amounts), trim($splitRule), trim($firstShare), $household, $lineIds, $locale, self::texts($childIncomes));
    }

    public function isValid(): bool
    {
        return $this->budget !== null;
    }

    private function cents(string $input, string $field, string $locale): int
    {
        if ($input === '') {
            return 0;
        }
        try {
            $cents = Money::parse($input, $locale);
        } catch (\InvalidArgumentException $e) {
            $this->errors[$field] = t($e->getMessage());

            return 0;
        }
        if ($cents < 0) {
            $this->errors[$field] = t('Le montant ne peut pas être négatif.');
        } elseif ($cents > MonthlyBudget::MAX_CENTS) {
            $this->errors[$field] = t('Montant trop grand.');
        }

        return isset($this->errors[$field]) ? 0 : $cents;
    }

    /**
     * @param array<mixed> $values
     *
     * @return array<int, string>
     */
    private static function texts(array $values): array
    {
        $texts = [];
        foreach (array_keys($values) as $key) {
            if (is_int($key) && is_string($values[$key])) {
                $texts[$key] = trim($values[$key]);
            }
        }

        return $texts;
    }
}
