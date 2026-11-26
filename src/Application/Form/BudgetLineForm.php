<?php

declare(strict_types=1);

namespace Patrimeter\Application\Form;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\BudgetLine;
use Patrimeter\Domain\Budget\Household;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Form of a budget item: name, category, possible child and, for a savings item, the account that receives the
 * payment. Categories and accounts offered: the user's only; an account chosen for an item that is not savings is
 * ignored.
 */
final class BudgetLineForm
{
    /** @var array<string, TranslatableInterface> errors by field */
    public array $errors = [];
    public ?BudgetCategory $categoryValue = null;
    public ?int $childValue = null;
    public ?int $accountValue = null;

    /**
     * @param list<BudgetCategory> $categories the user's categories
     * @param list<Account>        $accounts   asset accounts a savings item can point to
     */
    private function __construct(
        public readonly string $name,
        public readonly string $category,
        public readonly string $child,
        public readonly string $account,
        array $categories,
        array $accounts,
        Household $household,
    ) {
        if (!BudgetCategory::isValidName($name)) {
            $this->errors['name'] = t('Indiquez un nom de 1 à 60 caractères.');
        }
        foreach ($categories as $candidate) {
            if ((string) $candidate->id === $category) {
                $this->categoryValue = $candidate;
            }
        }
        if ($this->categoryValue === null) {
            $this->errors['category'] = t('Choisissez une catégorie.');
        }
        if ($child !== '') {
            $this->childValue = ctype_digit($child) ? (int) $child : 0;
            if (!$household->hasChild($this->childValue)) {
                $this->errors['child'] = t('Choisissez un enfant du foyer.');
            }
        }
        if ($account !== '' && $this->categoryValue?->group === BudgetGroup::Savings) {
            foreach ($accounts as $candidate) {
                if ((string) $candidate->id === $account) {
                    $this->accountValue = $candidate->id;
                }
            }
            if ($this->accountValue === null) {
                $this->errors['account'] = t('Choisissez un de vos comptes d\'épargne.');
            }
        }
    }

    /**
     * @param list<BudgetCategory> $categories
     * @param list<Account>        $accounts
     */
    public static function validate(string $name, string $category, string $child, string $account, array $categories, array $accounts, Household $household): self
    {
        return new self(trim($name), trim($category), trim($child), trim($account), $categories, $accounts, $household);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function line(int $id): BudgetLine
    {
        if ($this->categoryValue === null || !$this->isValid()) {
            throw new \LogicException('Poste invalide.');
        }

        return new BudgetLine($id, $this->categoryValue->id, $this->name, $this->childValue, $this->accountValue);
    }
}
