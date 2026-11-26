<?php

declare(strict_types=1);

namespace Patrimeter\Application\Form;

use Patrimeter\Domain\Budget\BudgetCategory;
use Patrimeter\Domain\Budget\BudgetGroup;
use Patrimeter\Domain\Budget\Household;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/** Form of a budget category: name, group (50/30/20), shared by the household or belonging to one adult. */
final class BudgetCategoryForm
{
    /** @var array<string, TranslatableInterface> errors by field */
    public array $errors = [];
    public ?BudgetGroup $groupValue;
    public ?int $adultValue = null;

    private function __construct(
        public readonly string $name,
        public readonly string $group,
        public readonly string $adult,
        Household $household,
    ) {
        if (!BudgetCategory::isValidName($name)) {
            $this->errors['name'] = t('Indiquez un nom de 1 à 60 caractères.');
        }
        $this->groupValue = BudgetGroup::tryFrom($group);
        if ($this->groupValue === null) {
            $this->errors['group'] = t('Choisissez besoins, envies ou épargne.');
        }
        if ($adult !== '') {
            $this->adultValue = ctype_digit($adult) ? (int) $adult : 0;
            if (!$household->hasAdult($this->adultValue)) {
                $this->errors['adult'] = t('Choisissez le budget commun ou un adulte du foyer.');
            }
        }
    }

    public static function validate(string $name, string $group, string $adult, Household $household): self
    {
        return new self(trim($name), trim($group), trim($adult), $household);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function category(int $id): BudgetCategory
    {
        if ($this->groupValue === null || !$this->isValid()) {
            throw new \LogicException('Catégorie invalide.');
        }

        return new BudgetCategory($id, $this->name, $this->groupValue, $this->adultValue);
    }
}
