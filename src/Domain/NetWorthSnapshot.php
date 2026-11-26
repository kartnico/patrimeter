<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/** Net worth of a month, broken down by pyramid level. */
final class NetWorthSnapshot
{
    /** @param array<string, LevelTotal> $levels indexed by level code, in the order of Level::ordered() */
    public function __construct(
        public readonly Month $month,
        public readonly array $levels,
        public readonly int $enteredCount,
        public readonly int $carriedCount,
    ) {}

    public function level(Level $level): LevelTotal
    {
        return $this->levels[$level->value];
    }

    public function net(): int
    {
        return array_sum(array_map(static fn(LevelTotal $l): int => $l->net(), $this->levels));
    }

    public function assets(): int
    {
        return array_sum(array_map(static fn(LevelTotal $l): int => $l->assets, $this->levels));
    }

    public function liabilities(): int
    {
        return array_sum(array_map(static fn(LevelTotal $l): int => $l->liabilities, $this->levels));
    }

    /**
     * Net of the chosen accounts (assets minus liabilities, ownership shares applied), leaving out those of the levels
     * in $except, already counted by whoever asks.
     *
     * @param list<int>   $accountIds
     * @param list<Level> $except
     */
    public function accountsNet(array $accountIds, array $except = []): int
    {
        $total = 0;
        foreach ($this->levels as $level) {
            foreach ($level->lines as ['account' => $account, 'value' => $value]) {
                if (in_array($account->id, $accountIds, true) && !in_array($account->level, $except, true)) {
                    $total += $account->isLiability() ? -$value->cents : $value->cents;
                }
            }
        }

        return $total;
    }

    /** True if at least one account has a value (entered or carried over) this month. */
    public function hasData(): bool
    {
        return $this->enteredCount + $this->carriedCount > 0;
    }

    /**
     * Gap with a reference snapshot: null if either has no data; percentage only if the reference is strictly
     * positive (otherwise not meaningful).
     *
     * @return array{month: Month, variation: ?int, percent: ?float}
     */
    public function comparedTo(self $reference): array
    {
        $variation = null;
        $percent = null;
        if ($this->hasData() && $reference->hasData()) {
            $variation = $this->net() - $reference->net();
            if ($reference->net() > 0) {
                $percent = $variation * 100 / $reference->net();
            }
        }

        return ['month' => $reference->month, 'variation' => $variation, 'percent' => $percent];
    }

    /**
     * Share of the level in the total net worth, in percent (display only).
     * Null if the total is not strictly positive (share not meaningful).
     */
    public function share(Level $level): ?float
    {
        $total = $this->net();
        if ($total <= 0) {
            return null;
        }

        return $this->level($level)->net() * 100 / $total;
    }
}
