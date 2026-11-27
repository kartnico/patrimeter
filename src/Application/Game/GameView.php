<?php

declare(strict_types=1);

namespace Patrimeter\Application\Game;

use Patrimeter\Domain\Game\Challenge;
use Patrimeter\Domain\Game\ChallengeFamily;
use Patrimeter\Domain\Game\ChallengeResult;
use Patrimeter\Domain\Game\FirstStep;
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

    /** Stars still to earn, out of maxStars(). */
    public function remainingStars(): int
    {
        return max(0, $this->maxStars() - $this->totalStars());
    }

    /** @return list<array{family: ChallengeFamily, stars: int, max: int}> stars kept and stars possible, by family */
    public function families(): array
    {
        return array_map(function (ChallengeFamily $family): array {
            $stars = 0;
            $max = 0;
            foreach ($this->results as $result) {
                if ($result->challenge->family() === $family) {
                    $stars += $this->stars($result->challenge);
                    $max += $result->maxStars();
                }
            }

            return ['family' => $family, 'stars' => $stars, 'max' => $max];
        }, ChallengeFamily::cases());
    }

    /** The card of a challenge: its kept stars, its rail and what is missing for the next star. */
    public function card(ChallengeResult $result): ChallengeCard
    {
        $remaining = $result->challenge === Challenge::FirstSteps
            ? array_values(array_filter(FirstStep::cases(), fn(FirstStep $step): bool => !$step->isDone($this->facts)))
            : [];

        return new ChallengeCard($result, $this->stars($result->challenge), $remaining);
    }

    /** Rank of the stage reached, from 1 (Discovery), or null before the first month entered. */
    public function stageNumber(): ?int
    {
        $stage = $this->stage();
        foreach (SaverStage::cases() as $index => $case) {
            if ($case === $stage) {
                return $index + 1;
            }
        }

        return null;
    }

    /** The stage after the one reached (Discovery before the first month entered), or null at the last one. */
    public function nextStage(): ?SaverStage
    {
        $stage = $this->stage();

        return $stage === null ? SaverStage::Discovery : $stage->next();
    }

    /** @return array{value: int, target: int}|null how far the next stage is, when it can be counted */
    public function nextStageMeasure(): ?array
    {
        return $this->nextStage()?->measure($this->facts);
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
