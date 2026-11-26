<?php

declare(strict_types=1);

namespace Patrimeter\Application\Game;

use Patrimeter\Application\Calendar;
use Patrimeter\Domain\Game\Challenge;
use Patrimeter\Domain\Game\ChallengeResult;
use Patrimeter\Domain\Game\FirstStep;
use Patrimeter\Domain\Game\SaverLevel;
use Patrimeter\Domain\Game\SaverStyle;
use Patrimeter\Domain\Game\StreakNudge;
use Patrimeter\Domain\Month;
use Patrimeter\Domain\UserId;
use Patrimeter\Infrastructure\Persistence\GameRepository;
use Patrimeter\Infrastructure\Persistence\StatementRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Challenges and stars: measures the challenges this month, keeps the best stars of each one (never lowered) and gives
 * the stars just earned, to announce them after the monthly entry. Off for the whole instance with GAMIFICATION=false,
 * or for a user who turned it off.
 */
final class Game
{
    /** Savings rates offered for the savings rate challenge, in percent: the user's choice, none is suggested. */
    public const array SAVINGS_RATES = [5, 10, 15, 20, 25, 30, 40, 50];

    public function __construct(
        private readonly GameFactsBuilder $facts,
        private readonly GameRepository $repository,
        private readonly Calendar $calendar,
        private readonly StatementRepository $statements,
        #[Autowire(env: 'bool:GAMIFICATION')]
        private readonly bool $instanceEnabled,
    ) {}

    /** Whether the instance offers the game at all. */
    public function isOffered(): bool
    {
        return $this->instanceEnabled;
    }

    public function isEnabled(UserId $owner): bool
    {
        return $this->instanceEnabled && $this->repository->settings($owner)['enabled'];
    }

    /** The game this month; the stars that beat the best ones stored are kept. */
    public function view(UserId $owner): GameView
    {
        return $this->measure($owner)[0];
    }

    /**
     * Records the stars earned since the last visit and returns them, for the message after the monthly entry.
     *
     * @return list<array{challenge: Challenge, stars: int, max: int}>
     */
    public function newStars(UserId $owner): array
    {
        return $this->instanceEnabled && $this->repository->settings($owner)['enabled'] ? $this->measure($owner)[1] : [];
    }

    /**
     * Level reached, from the stars already kept (no challenge measured again: cheap enough for every dashboard);
     * null when the challenges are off.
     *
     * @return array{level: SaverLevel, stars: int}|null
     */
    public function keptLevel(UserId $owner): ?array
    {
        if (!$this->isEnabled($owner)) {
            return null;
        }
        $stars = array_sum($this->repository->stars($owner));

        return ['level' => SaverLevel::of($stars), 'stars' => $stars];
    }

    /**
     * The first-steps card of the dashboard: each step and whether it is done, or null once the five steps are done (a
     * star each, kept), when the user hid the card or turned the challenges off. Measures the first steps only: the
     * dashboard does not pay for the other challenges.
     *
     * @return list<array{step: FirstStep, done: bool}>|null
     */
    public function firstSteps(UserId $owner): ?array
    {
        $settings = $this->repository->settings($owner);
        $best = $this->repository->stars($owner)[Challenge::FirstSteps->value] ?? 0;
        $total = count(FirstStep::cases());
        if (!$this->instanceEnabled || !$settings['enabled'] || $settings['first_steps_hidden'] || $best >= $total) {
            return null;
        }
        $facts = $this->facts->firstStepFacts($owner, $this->calendar->currentMonth());
        $steps = array_map(static fn(FirstStep $step): array => ['step' => $step, 'done' => $step->isDone($facts)], FirstStep::cases());
        $done = count(array_filter($steps, static fn(array $step): bool => $step['done']));
        if ($done > $best) {
            $this->repository->raise($owner, Challenge::FirstSteps, $done, $this->calendar->today());
        }

        return $done < $total ? $steps : null;
    }

    /**
     * The entry streak and what entering $month would bring, for the monthly reminder; null when the challenges are off.
     * Reads the months entered and the stars kept only, in clear: no session needed, no amount.
     */
    public function streakNudge(UserId $owner, Month $month): ?StreakNudge
    {
        if (!$this->isEnabled($owner)) {
            return null;
        }
        $entered = array_values(array_filter($this->statements->months($owner), static fn(Month $m): bool => $m->compare($month) < 0));

        return StreakNudge::of($entered, $month, $this->repository->stars($owner)[Challenge::Streak->value] ?? 0);
    }

    public function setEnabled(UserId $owner, bool $enabled): void
    {
        $this->repository->saveEnabled($owner, $enabled);
    }

    /** Saver style the user declared; null: none. */
    public function style(UserId $owner): ?SaverStyle
    {
        return $this->repository->settings($owner)['style'];
    }

    public function setStyle(UserId $owner, ?SaverStyle $style): void
    {
        $this->repository->saveStyle($owner, $style);
    }

    /** Savings rate aimed at by the savings rate challenge, chosen among SAVINGS_RATES; null (or another value): none. */
    public function setSavingsRateTarget(UserId $owner, ?int $percent): void
    {
        $this->repository->saveSavingsRateTarget($owner, in_array($percent, self::SAVINGS_RATES, true) ? $percent : null);
    }

    public function savingsRateTarget(UserId $owner): ?int
    {
        return $this->repository->settings($owner)['savings_rate_target'];
    }

    public function setFirstStepsHidden(UserId $owner, bool $hidden): void
    {
        $this->repository->saveFirstStepsHidden($owner, $hidden);
    }

    /** @return array{GameView, list<array{challenge: Challenge, stars: int, max: int}>} */
    private function measure(UserId $owner): array
    {
        $settings = $this->repository->settings($owner);
        $facts = $this->facts->facts($owner, $this->calendar->currentMonth(), $settings['savings_rate_target']);
        $best = $this->repository->stars($owner);
        $earned = [];
        $results = [];
        foreach (Challenge::cases() as $challenge) {
            $result = $challenge->evaluate($facts);
            $results[$challenge->value] = $result;
            $stars = $result->stars();
            if ($stars > ($best[$challenge->value] ?? 0)) {
                $this->repository->raise($owner, $challenge, $stars, $this->calendar->today());
                $best[$challenge->value] = $stars;
                $earned[] = ['challenge' => $challenge, 'stars' => $stars, 'max' => $result->maxStars()];
            }
        }
        $order = $settings['style']?->order() ?? SaverStyle::defaultOrder();
        $firstSteps = $results[Challenge::FirstSteps->value];
        $ordered = array_map(static fn(Challenge $challenge): ChallengeResult => $results[$challenge->value], $order);
        $ordered = ($best[Challenge::FirstSteps->value] ?? 0) < $firstSteps->maxStars() ? [$firstSteps, ...$ordered] : [...$ordered, $firstSteps];

        return [new GameView($facts, $ordered, $best, $settings['style'], $settings['first_steps_hidden']), $earned];
    }
}
