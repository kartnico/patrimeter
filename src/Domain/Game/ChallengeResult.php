<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Game;

/**
 * Where a challenge stands this month: the value measured, the thresholds of its stars (same unit, ascending) and the
 * stars they give. "Available" is false when the data needed is missing (no budget, no goal): the challenge then says
 * what to do first.
 */
final class ChallengeResult
{
    /**
     * @param non-empty-list<int>       $thresholds value needed for the first, second… star
     * @param array<string, string|int> $parameters of the progress sentence (Challenge::progressLabel())
     */
    public function __construct(
        public readonly Challenge $challenge,
        public readonly int $value,
        public readonly array $thresholds,
        public readonly bool $available = true,
        public readonly array $parameters = [],
    ) {}

    /** @return array<string, string|int> parameters of Challenge::progressLabel(), the value measured included */
    public function messageParameters(): array
    {
        return ['%count%' => $this->value] + $this->parameters;
    }

    /** Stars given by the value measured this month (the best ones ever reached are kept apart). */
    public function stars(): int
    {
        if (!$this->available) {
            return 0;
        }

        return count(array_filter($this->thresholds, fn(int $threshold): bool => $this->value >= $threshold));
    }

    public function maxStars(): int
    {
        return count($this->thresholds);
    }

    /** Value of the next star, or null once they are all reached. */
    public function nextThreshold(): ?int
    {
        return $this->available ? ($this->thresholds[$this->stars()] ?? null) : $this->thresholds[0];
    }

    /** Progress towards the next star, from the previous threshold: 0 to 100. */
    public function percent(): int
    {
        $next = $this->nextThreshold();
        if ($next === null) {
            return 100;
        }
        $stars = $this->stars();
        $from = $stars === 0 ? 0 : ($this->thresholds[$stars - 1] ?? 0);
        if (!$this->available || $next <= $from) {
            return 0;
        }

        return max(0, min(100, intdiv(100 * ($this->value - $from), $next - $from)));
    }
}
