<?php

declare(strict_types=1);

namespace Patrimeter\Application\Family;

use Patrimeter\Domain\Account;
use Patrimeter\Domain\NetWorthCalculator;
use Patrimeter\Domain\OwnershipShares;
use Patrimeter\Domain\Valuation;

/**
 * Accounts, statements and shares of a perimeter (Perimeter), ready for the net worth calculation. The accounts
 * imported from a member have a negative identifier and the name of their member ($members): they can be read, not
 * changed.
 */
final class Portfolio
{
    /**
     * @param list<Account>                  $accounts
     * @param array<int, array<string, int>> $statements account => (YYYY-MM => cents)
     * @param array<int, array<string, int>> $shares     account => (YYYY-MM => millionths)
     * @param array<int, string>             $members    imported account => name of its member
     */
    public function __construct(
        public readonly array $accounts,
        public readonly array $statements,
        public readonly array $shares,
        public readonly array $members = [],
    ) {}

    public function calculator(): NetWorthCalculator
    {
        return new NetWorthCalculator($this->accounts, $this->valuation(), new OwnershipShares($this->shares));
    }

    public function valuation(): Valuation
    {
        return new Valuation($this->statements);
    }

    /** @return array<int, Account> accounts by identifier */
    public function byId(): array
    {
        $accounts = [];
        foreach ($this->accounts as $account) {
            $accounts[$account->id] = $account;
        }

        return $accounts;
    }
}
