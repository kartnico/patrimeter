<?php

declare(strict_types=1);

namespace Patrimeter\Domain;

/**
 * An "about how much?" answer of the beginner questions: a range of whole euros, worth its middle. Two scales, for
 * savings and for a home (or its loan). The amount is approximate by nature: the balance created is flagged "to refine".
 */
final class AmountRange
{
    /** Bounds in euros of the savings scale, then of the home scale: [0, 1 000], [1 000, 5 000]… */
    private const array SAVINGS = [0, 1_000, 5_000, 20_000, 50_000, 100_000];
    private const array HOME = [0, 100_000, 200_000, 300_000, 500_000, 1_000_000];

    private function __construct(
        public readonly int $from,
        public readonly int $to,
    ) {}

    /** @return list<self> ranges of the savings scale, ascending */
    public static function savings(): array
    {
        return self::scale(self::SAVINGS);
    }

    /** @return list<self> ranges of the home scale (value of a home, or its loan), ascending */
    public static function home(): array
    {
        return self::scale(self::HOME);
    }

    /** The range of a scale from its key ("1000-5000"), or null if it is not one of them. */
    public static function tryFromKey(string $key, bool $home): ?self
    {
        foreach ($home ? self::home() : self::savings() as $range) {
            if ($range->key() === $key) {
                return $range;
            }
        }

        return null;
    }

    public function key(): string
    {
        return $this->from . '-' . $this->to;
    }

    /** The middle of the range, in cents: the amount retained. */
    public function cents(): int
    {
        return intdiv($this->from + $this->to, 2) * 100;
    }

    /**
     * @param non-empty-list<int> $bounds
     *
     * @return list<self>
     */
    private static function scale(array $bounds): array
    {
        $ranges = [];
        for ($i = 1, $count = count($bounds); $i < $count; ++$i) {
            $ranges[] = new self($bounds[$i - 1], $bounds[$i]);
        }

        return $ranges;
    }
}
