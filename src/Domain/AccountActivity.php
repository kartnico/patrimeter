<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * Months in which a set of accounts has something to enter: from the first opening month to the month after the last
 * closing (open-ended while an account stays open). A gap between two accounts (one closed, the next opened later)
 * counts as active: this span only lets the monthly reminder, sent without the user's key, skip a household whose
 * accounts are all closed or not opened yet. The entry form itself applies Account::isActiveIn() to each account.
 */
final class AccountActivity
{
    private function __construct(
        public readonly ?Month $from,
        public readonly ?Month $until,
    ) {}

    /** @param iterable<Account> $accounts */
    public static function of(iterable $accounts): self
    {
        $from = null;
        $until = null;
        $open = false;
        foreach ($accounts as $account) {
            $opened = Month::fromDate($account->openedOn);
            $from = $from === null || $opened->compare($from) < 0 ? $opened : $from;
            if ($account->closedOn === null) {
                $open = true;
                continue;
            }
            $closed = Month::fromDate($account->closedOn);
            $until = $until === null || $closed->compare($until) > 0 ? $closed : $until;
        }

        return new self($from, $from === null || $open ? null : $until);
    }

    /** Whether the month falls in the span: on or after the first opening, before the last closing month. */
    public function covers(Month $month): bool
    {
        return $this->from !== null && $this->from->compare($month) <= 0 && ($this->until === null || $this->until->compare($month) > 0);
    }
}
