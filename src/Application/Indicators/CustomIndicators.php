<?php

declare(strict_types=1);

namespace Patrimeter\Application\Indicators;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\Indicator\CustomIndicator;
use Patrimeter\Domain\Level;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\NetWorthSnapshot;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\AccountRepository;
use Patrimeter\Infrastructure\Persistence\CustomIndicatorRepository;
use Patrimeter\Infrastructure\Persistence\OwnershipShareRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

/**
 * Custom indicators of a user: creation and change from the form (only the user's own levels and accounts), deletion,
 * and their values, computed from the statements each time as the net worth: nothing else is stored.
 *
 * @phpstan-type IndicatorFields array{name: string, levels: list<string>, accounts: list<string>, share: bool}
 * @phpstan-type IndicatorValue array{indicator: CustomIndicator, value: int, previous: ?int, share: ?float}
 */
final class CustomIndicators
{
    /** Months of the history table. */
    public const int HISTORY_MONTHS = 12;

    public function __construct(
        private readonly CustomIndicatorRepository $indicators,
        private readonly AccountRepository $accounts,
        private readonly StatementRepository $statements,
        private readonly OwnershipShareRepository $shares,
    ) {}

    public function find(UserId $owner, int $id): ?CustomIndicator
    {
        return $this->indicators->find($owner, $id);
    }

    public function isFull(UserId $owner): bool
    {
        return $this->indicators->count($owner) >= CustomIndicatorRepository::MAX_INDICATORS;
    }

    /** @return list<Account> accounts an indicator can add up (not archived) */
    public function accounts(UserId $owner): array
    {
        return array_values(array_filter($this->accounts->all($owner), static fn(Account $account): bool => !$account->isArchived()));
    }

    /**
     * Each indicator in $month, with its value the month before and its share of the net worth if asked.
     *
     * @return list<IndicatorValue>
     */
    public function values(UserId $owner, Month $month): array
    {
        $calculator = $this->calculator($owner);

        return $this->valuesIn($owner, $calculator->snapshot($month), $calculator->snapshot($month->previous()));
    }

    /**
     * Same, from the net worth already computed by the page (the dashboard): no statement read again.
     *
     * @return list<IndicatorValue>
     */
    public function valuesIn(UserId $owner, NetWorthSnapshot $now, NetWorthSnapshot $before): array
    {
        return array_map(static fn(CustomIndicator $indicator): array => [
            'indicator' => $indicator,
            'value' => $indicator->valueIn($now),
            'previous' => $before->hasData() ? $indicator->valueIn($before) : null,
            'share' => $indicator->shareOfNetWorth ? $indicator->shareIn($now) : null,
        ], $this->indicators->all($owner));
    }

    /**
     * The indicator over the last HISTORY_MONTHS months up to $month, the most recent first; months without any data
     * left out.
     *
     * @return list<array{month: Month, value: int, share: ?float}>
     */
    public function history(UserId $owner, CustomIndicator $indicator, Month $month): array
    {
        $calculator = $this->calculator($owner);
        $rows = [];
        foreach (array_reverse(Month::range($month->addMonths(1 - self::HISTORY_MONTHS), $month)) as $m) {
            $snapshot = $calculator->snapshot($m);
            if ($snapshot->hasData()) {
                $rows[] = ['month' => $m, 'value' => $indicator->valueIn($snapshot), 'share' => $indicator->shareOfNetWorth ? $indicator->shareIn($snapshot) : null];
            }
        }

        return $rows;
    }

    /**
     * Creates ($existing null) or changes an indicator; returns the errors by field, empty when it was saved.
     *
     * @param IndicatorFields $fields
     *
     * @return array<string, TranslatableInterface>
     */
    public function save(UserId $owner, ?CustomIndicator $existing, array $fields): array
    {
        $name = trim($fields['name']);
        $levels = array_values(array_filter(array_map(Level::tryFrom(...), $fields['levels'])));
        $own = array_map(static fn(Account $account): int => $account->id, $this->accounts($owner));
        $accountIds = array_values(array_intersect($own, array_map('intval', $fields['accounts'])));
        $errors = [];
        if (!CustomIndicator::isValidName($name)) {
            $errors['name'] = t('Nom obligatoire, %max% caractères au plus.', ['%max%' => CustomIndicator::NAME_MAX]);
        }
        if ($levels === [] && $accountIds === []) {
            $errors['perimeter'] = t('Choisissez au moins un niveau ou un compte.');
        }
        if ($existing === null && $this->isFull($owner)) {
            $errors['name'] = t('%max% indicateurs au plus.', ['%max%' => CustomIndicatorRepository::MAX_INDICATORS]);
        }
        if ($errors !== []) {
            return $errors;
        }
        $indicator = new CustomIndicator($existing?->id, $name, $levels, $accountIds, $fields['share']);
        if ($existing === null) {
            $this->indicators->create($owner, $indicator);
        } else {
            $this->indicators->update($owner, $indicator);
        }

        return [];
    }

    public function delete(UserId $owner, int $id): void
    {
        $this->indicators->delete($owner, $id);
    }

    private function calculator(UserId $owner): NetWorthCalculator
    {
        return new NetWorthCalculator($this->accounts->all($owner), $this->statements->valuation($owner), new OwnershipShares($this->shares->all($owner)));
    }
}
