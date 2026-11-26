<?php

declare(strict_types=1);

namespace Patrimeter\Application\Form;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Goal\GoalScope;
use Patrimeter\Domain\Goal\SavingsGoal;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Money;
use Patrimeter\Domain\Month;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Form of a savings goal: name, target amount, target month (after $earliest), perimeter (net worth, one level, or
 * accounts of the owner).
 */
final class GoalForm
{
    /** @var array<string, TranslatableInterface> errors by field */
    public array $errors = [];
    public ?int $targetCents = null;
    public ?Month $targetMonthValue = null;
    public ?GoalScope $scopeValue;
    public ?Level $levelValue = null;
    /** @var list<int> */
    public array $accountIds = [];
    /** Target in months of spending (kind "spending"), or null for an amount. */
    public ?int $spendingMonths = null;
    /** Start month of the goal: its value then is the starting point of the trajectory. */
    public ?Month $startMonthValue = null;

    /**
     * @param list<string>  $accounts    identifiers ticked
     * @param list<Account> $ownAccounts accounts of the owner
     */
    private function __construct(
        public readonly string $name,
        public readonly string $target,
        public readonly string $targetMonth,
        public readonly string $scope,
        public readonly string $level,
        public readonly string $kind,
        public readonly string $months,
        public readonly string $start,
        array $accounts,
        array $ownAccounts,
        Month $earliest,
        Month $defaultStart,
        Month $latestStart,
        string $locale,
    ) {
        if (!SavingsGoal::isValidName($name)) {
            $this->errors['name'] = t('Indiquez un nom de 1 à 60 caractères.');
        }
        if ($kind === 'spending') {
            $this->spendingMonths = ctype_digit($months) ? (int) $months : 0;
            if ($this->spendingMonths < 1 || $this->spendingMonths > SavingsGoal::SPENDING_MONTHS_MAX) {
                $this->errors['spending_months'] = t('Indiquez un nombre de mois de 1 à %max%.', ['%max%' => SavingsGoal::SPENDING_MONTHS_MAX]);
            }
        } else {
            try {
                $this->targetCents = Money::parse($target, $locale);
            } catch (\InvalidArgumentException) {
                $this->targetCents = null;
            }
            if ($this->targetCents === null || $this->targetCents <= 0 || $this->targetCents > SavingsGoal::TARGET_MAX) {
                $this->errors['target'] = t('Indiquez un montant positif, 100 millions d\'euros au plus.');
            }
        }
        $this->startMonthValue = $start === '' ? $defaultStart : Month::tryFromString($start);
        if ($this->startMonthValue === null || $this->startMonthValue->compare($latestStart) > 0 || $this->startMonthValue->monthsUntil($latestStart) > 1200) {
            $this->errors['start_month'] = t('Choisissez un mois de départ passé, ou celui-ci (%month% au plus tard).', ['%month%' => $latestStart->toString()]);
            $this->startMonthValue = null;
        }
        $earliest = $this->startMonthValue !== null && $this->startMonthValue->next()->compare($earliest) > 0 ? $this->startMonthValue->next() : $earliest;
        $this->targetMonthValue = Month::tryFromString($targetMonth);
        if ($this->targetMonthValue === null || $this->targetMonthValue->compare($earliest) < 0 || $earliest->monthsUntil($this->targetMonthValue) > 1200) {
            $this->errors['target_month'] = t('Choisissez un mois à partir de %month%, dans les 100 ans.', ['%month%' => $earliest->toString()]);
        }
        $this->scopeValue = GoalScope::tryFrom($scope);
        if ($this->scopeValue === null) {
            $this->errors['scope'] = t('Choisissez ce que l\'objectif mesure.');
        }
        if ($this->scopeValue === GoalScope::Level) {
            $this->levelValue = Level::tryFrom($level);
            if ($this->levelValue === null) {
                $this->errors['level'] = t('Choisissez un niveau de la pyramide.');
            }
        }
        if ($this->scopeValue === GoalScope::Accounts) {
            $own = array_map(static fn(Account $account): int => $account->id, $ownAccounts);
            foreach ($accounts as $value) {
                if (ctype_digit($value) && in_array((int) $value, $own, true) && !in_array((int) $value, $this->accountIds, true)) {
                    $this->accountIds[] = (int) $value;
                }
            }
            if ($this->accountIds === [] || count($this->accountIds) !== count(array_unique($accounts))) {
                $this->errors['accounts'] = t('Cochez au moins un de vos comptes.');
            }
        }
    }

    /**
     * Target month from $earliest (and after the start month); start month up to $latestStart, $defaultStart if empty.
     *
     * @param array{name: string, target: string, target_month: string, scope: string, level: string, kind: string, spending_months: string, start_month: string, accounts: list<string>} $fields
     * @param list<Account>                                                                                                                                                              $ownAccounts
     */
    public static function validate(array $fields, array $ownAccounts, Month $earliest, Month $defaultStart, Month $latestStart, string $locale): self
    {
        return new self(
            trim($fields['name']),
            trim($fields['target']),
            trim($fields['target_month']),
            trim($fields['scope']),
            trim($fields['level']),
            trim($fields['kind']),
            trim($fields['spending_months']),
            trim($fields['start_month']),
            $fields['accounts'],
            $ownAccounts,
            $earliest,
            $defaultStart,
            $latestStart,
            $locale,
        );
    }

    /** No household spending to compute a target in months of spending. */
    public function refuseSpending(): void
    {
        $this->errors['spending_months'] = t('Saisissez d\'abord le budget du foyer : la cible suit ses dépenses.');
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * Goal described by the form, with its identifier and starting point; $spendingTarget: amount of the months of
     * spending this month (target in months of spending).
     */
    public function goal(int $id, int $startCents, ?int $spendingTarget = null): SavingsGoal
    {
        $target = $this->spendingMonths === null ? $this->targetCents : $spendingTarget;
        if (!$this->isValid() || $target === null || $this->targetMonthValue === null || $this->scopeValue === null || $this->startMonthValue === null) {
            throw new \LogicException('Objectif invalide.');
        }

        return new SavingsGoal($id, $this->name, $target, $this->targetMonthValue, $this->scopeValue, $this->levelValue, $this->accountIds, $this->startMonthValue, $startCents, $this->spendingMonths);
    }
}
