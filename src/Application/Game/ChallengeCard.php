<?php

declare(strict_types=1);

namespace Patrimeter\Application\Game;

use Patrimeter\Domain\Game\Challenge;
use Patrimeter\Domain\Game\ChallengeResult;
use Patrimeter\Domain\Game\FirstStep;

/**
 * A challenge as its card on the Défis page shows it: the stars kept (the best ever reached), the state of each
 * threshold on the rail, and what is still missing for the next star. Prepared here so that the template only displays.
 */
final class ChallengeCard
{
    public const string EARNED = 'earned';
    public const string NEXT = 'next';
    public const string LATER = 'later';

    /** @param list<FirstStep> $remainingSteps first steps not done yet ("Premiers pas" only) */
    public function __construct(
        public readonly ChallengeResult $result,
        public readonly int $stars,
        public readonly array $remainingSteps = [],
    ) {}

    public function challenge(): Challenge
    {
        return $this->result->challenge;
    }

    public function isComplete(): bool
    {
        return $this->stars >= $this->result->maxStars();
    }

    /** @return list<array{threshold: int, state: string}> the thresholds in order, earned, next or later */
    public function steps(): array
    {
        $steps = [];
        foreach ($this->result->thresholds as $index => $threshold) {
            $state = match (true) {
                $index < $this->stars => self::EARNED,
                $index === $this->stars => self::NEXT,
                default => self::LATER,
            };
            $steps[] = ['threshold' => $threshold, 'state' => $state];
        }

        return $steps;
    }

    /** Rank of the next star (1 for the first), or null once they are all earned. */
    public function nextRank(): ?int
    {
        return $this->isComplete() ? null : $this->stars + 1;
    }

    /** Heading of the block "what is missing": "Pour la 2ᵉ étoile" (French source string); null once complete. */
    public function nextStarLabel(): ?string
    {
        return match ($this->nextRank()) {
            null => null,
            1 => 'Pour la 1ʳᵉ étoile',
            2 => 'Pour la 2ᵉ étoile',
            3 => 'Pour la 3ᵉ étoile',
            4 => 'Pour la 4ᵉ étoile',
            default => 'Pour la 5ᵉ étoile',
        };
    }

    /**
     * What is still missing for the next star, from the value measured this month: at least 1, since the kept stars
     * already include every threshold reached. Null once complete, or while the challenge cannot be measured.
     */
    public function missing(): ?int
    {
        $next = $this->result->thresholds[$this->stars] ?? null;
        if ($next === null || !$this->result->available) {
            return null;
        }

        return max(1, $next - $this->result->value);
    }

    /** @return array<string, string|int> parameters of Challenge::missingLabel() */
    public function missingParameters(): array
    {
        return ['%count%' => $this->missing() ?? 0, '%next%' => $this->result->thresholds[$this->stars] ?? 0] + $this->result->parameters;
    }
}
