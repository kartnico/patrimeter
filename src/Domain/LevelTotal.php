<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** Totals of a pyramid level for a month. */
final class LevelTotal
{
    /** @var list<array{account: Account, value: AccountValue}> */
    public array $lines = [];
    public int $assets = 0;
    public int $liabilities = 0;

    public function __construct(public readonly Level $level) {}

    public function add(Account $account, AccountValue $value): void
    {
        $this->lines[] = ['account' => $account, 'value' => $value];
        if ($account->isLiability()) {
            $this->liabilities += $value->cents;
        } else {
            $this->assets += $value->cents;
        }
    }

    /** Net worth of the level: assets − attached liabilities. */
    public function net(): int
    {
        return $this->assets - $this->liabilities;
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }
}
