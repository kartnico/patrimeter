<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** Net worth by level, month by month (pure, no database). */
final class NetWorthCalculator
{
    /** @param list<Account> $accounts */
    public function __construct(
        private readonly array $accounts,
        private readonly Valuation $valuation,
        private readonly OwnershipShares $shares = new OwnershipShares(),
    ) {}

    public function snapshot(Month $month): NetWorthSnapshot
    {
        $levels = [];
        $entered = 0;
        $carried = 0;
        foreach (Level::ordered() as $level) {
            $total = new LevelTotal($level);
            foreach ($this->accounts as $account) {
                if ($account->level !== $level || !$account->isActiveIn($month)) {
                    continue;
                }
                $value = $this->valuation->valueAt($account->id, $month);
                if ($value === null) {
                    continue;
                }
                $value->carried ? ++$carried : ++$entered;
                $total->add($account, $this->retained($account, $value, $month));
            }
            $levels[$level->value] = $total;
        }

        return new NetWorthSnapshot($month, $levels, $entered, $carried);
    }

    /**
     * Value retained: an asset according to its share; a linked loan according to its property's share, unless it
     * is entered as one's own amount.
     */
    private function retained(Account $account, AccountValue $value, Month $month): AccountValue
    {
        $share = $this->shares->retainedShare($account, $month);
        if ($share === OwnershipShares::FULL) {
            return $value;
        }

        return new AccountValue(OwnershipShares::apply($value->cents, $share), $value->sourceMonth, $value->carried);
    }

    /**
     * Monthly series from $from to $to inclusive.
     *
     * @return list<NetWorthSnapshot>
     */
    public function series(Month $from, Month $to): array
    {
        return array_map($this->snapshot(...), Month::range($from, $to));
    }

    /** First month with at least one statement, or null. */
    public function firstMonth(): ?Month
    {
        return $this->valuation->firstMonth();
    }
}
