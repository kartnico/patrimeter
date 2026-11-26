<?php

declare(strict_types=1);

namespace Patrimeter\Application\Game;

use Patrimeter\Domain\Game\Challenge;
use Patrimeter\Domain\Game\ChallengeResult;
use Patrimeter\Domain\Game\GameFacts;
use Patrimeter\Domain\Game\SaverLevel;
use Patrimeter\Domain\Game\SaverStage;
use Patrimeter\Domain\Game\SaverStyle;
use Patrimeter\Domain\Game\Streak;

/**
 * The game of a user this month: each challenge measured now, the best stars ever reached (kept, never lowered), the
 * level they give, the stage of the saver profile and the declared style.
 */
final class GameView
{
    /**
     * @param list<ChallengeResult> $results in the order of the style, "Premiers pas" first while it is not complete
     * @param array<string, int>    $best    best stars by challenge, this month's included
     */
    public function __construct(
        public readonly GameFacts $facts,
        public readonly array $results,
        private readonly array $best,
        public readonly ?SaverStyle $style,
        public readonly bool $firstStepsHidden,
    ) {}

    /** Stars shown for a challenge: the best ever reached, at most what the challenge gives now. */
    public function stars(Challenge $challenge): int
    {
        $best = $this->best[$challenge->value] ?? 0;
        foreach ($this->results as $result) {
            if ($result->challenge === $challenge) {
                return min($best, $result->maxStars());
            }
        }

        return $best;
    }

    public function totalStars(): int
    {
        return array_sum(array_map($this->stars(...), Challenge::cases()));
    }

    /** Most stars that can be earned together. */
    public function maxStars(): int
    {
        return SaverLevel::maxStars();
    }

    public function level(): SaverLevel
    {
        return SaverLevel::of($this->totalStars());
    }

    public function stage(): ?SaverStage
    {
        return SaverStage::of($this->facts);
    }

    /** Whether this rung of the profile is reached (it and every rung below it). */
    public function reaches(SaverStage $stage): bool
    {
        $current = $this->stage();

        return $current !== null && array_search($stage, SaverStage::cases(), true) <= array_search($current, SaverStage::cases(), true);
    }

    public function streak(): Streak
    {
        return $this->facts->streak();
    }

    /** @return list<ChallengeResult> challenges whose stars are not all earned yet */
    public function inProgress(): array
    {
        return array_values(array_filter($this->results, fn(ChallengeResult $result): bool => $this->stars($result->challenge) < $result->maxStars()));
    }

    /** @return list<ChallengeResult> challenges with all their stars */
    public function completed(): array
    {
        return array_values(array_filter($this->results, fn(ChallengeResult $result): bool => $this->stars($result->challenge) >= $result->maxStars()));
    }
}
