<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Indicator;

use Patrimeter\Domain\Level;
use Patrimeter\Domain\NetWorthSnapshot;

/**
 * Indicator chosen by the user: the total of some pyramid levels and of some accounts (for instance "Retirement": the
 * shares level and a life insurance), followed month by month, and if wished as a share of the net worth. An account
 * that belongs to a chosen level is counted once.
 */
final readonly class CustomIndicator
{
    public const int NAME_MAX = 60;

    /**
     * @param list<Level> $levels
     * @param list<int>   $accountIds
     */
    public function __construct(
        public ?int $id,
        public string $name,
        public array $levels,
        public array $accountIds,
        public bool $shareOfNetWorth,
    ) {}

    /** A name of 1 to NAME_MAX characters, without control characters. */
    public static function isValidName(string $name): bool
    {
        $length = mb_strlen($name);

        return $length >= 1 && $length <= self::NAME_MAX && preg_match('/[\x00-\x1F\x7F]/u', $name) !== 1;
    }

    /** Value in a month, as the net worth counts it (ownership shares and linked loans applied). */
    public function valueIn(NetWorthSnapshot $snapshot): int
    {
        $levels = array_sum(array_map(static fn(Level $level): int => $snapshot->level($level)->net(), $this->levels));

        return $levels + $snapshot->accountsNet($this->accountIds, $this->levels);
    }

    /** Share of the net worth in percent; null when the net worth is not positive (no meaningful share). */
    public function shareIn(NetWorthSnapshot $snapshot): ?float
    {
        $net = $snapshot->net();

        return $net > 0 ? $this->valueIn($snapshot) * 100 / $net : null;
    }

    /** Same indicator under another identifier (creation: the row is reserved first). */
    public function withId(int $id): self
    {
        return new self($id, $this->name, $this->levels, $this->accountIds, $this->shareOfNetWorth);
    }
}
