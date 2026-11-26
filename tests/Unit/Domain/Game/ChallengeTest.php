<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Domain\Game;

use Patrimeter\Domain\Game\Challenge;
use Patrimeter\Domain\Game\FirstStep;
use Patrimeter\Domain\Game\GameFacts;
use Patrimeter\Domain\Game\SaverLevel;
use Patrimeter\Domain\Game\SaverStage;
use Patrimeter\Domain\Game\SaverStyle;
use Patrimeter\Domain\Month;
use PHPUnit\Framework\TestCase;

/** Thresholds of the stars, stages of the saver profile, levels and order of the challenges. */
final class ChallengeTest extends TestCase
{
    public function testStarsFollowTheThresholds(): void
    {
        $facts = new GameFacts(
            month: Month::fromString('2026-11'),
            completeMonths: 6,
            precautionMonths: 2,
            precautionTarget: 6,
            consumerCreditFreeMonths: 12,
            budgetMonths: 2,
            goalName: 'Japon',
            goalPercent: 68,
            passkeys: 1,
        );

        $up = Challenge::UpToDate->evaluate($facts);
        self::assertSame([2, 3, 6], [$up->stars(), $up->maxStars(), $up->value]);
        self::assertSame(12, $up->nextThreshold());
        self::assertSame(0, $up->percent(), '6 of 12, from the second star at 6');

        $precaution = Challenge::Precaution->evaluate($facts);
        self::assertSame([1, 3, 6], $precaution->thresholds, 'one month, half of the target, the target');
        self::assertSame(1, $precaution->stars());
        self::assertSame(50, $precaution->percent(), '2 months, from 1 to 3');

        self::assertSame(3, Challenge::NoConsumerCredit->evaluate($facts)->stars());
        self::assertSame(1, Challenge::Budget->evaluate($facts)->stars());
        $goal = Challenge::Goal->evaluate($facts);
        self::assertSame(2, $goal->stars());
        self::assertSame(['%count%' => 68, '%name%' => 'Japon'], $goal->messageParameters());
        $security = Challenge::Security->evaluate($facts);
        self::assertSame([1, 2], [$security->stars(), $security->maxStars()]);
    }

    public function testWithoutBudgetNorGoalTheChallengeSaysWhatToDoFirst(): void
    {
        $facts = new GameFacts(Month::fromString('2026-11'));

        foreach ([Challenge::Precaution, Challenge::Goal] as $challenge) {
            $result = $challenge->evaluate($facts);
            self::assertFalse($result->available, $challenge->value);
            self::assertSame(0, $result->stars());
            self::assertNotNull($challenge->unavailableLabel());
        }
        self::assertNull(Challenge::Streak->unavailableLabel());
    }

    public function testTheSavingsRateChallengeNeedsARateChosenAndABudget(): void
    {
        $month = Month::fromString('2026-11');
        $noRate = Challenge::SavingsRate->evaluate(new GameFacts($month, budgetMonths: 3, savingsRateMonths: 3));
        self::assertFalse($noRate->available, 'no default rate: the user chooses it');
        self::assertSame(0, $noRate->stars());
        self::assertFalse(Challenge::SavingsRate->evaluate(new GameFacts($month, savingsRateTarget: 10))->available, 'no budget');

        $result = Challenge::SavingsRate->evaluate(new GameFacts($month, budgetMonths: 3, savingsRateTarget: 15, savingsRateMonths: 3));
        self::assertSame([2, 3], [$result->stars(), $result->maxStars()]);
        self::assertSame(['%count%' => 3, '%rate%' => 15], $result->messageParameters());
    }

    public function testFirstStepsGiveAStarEach(): void
    {
        $facts = new GameFacts(
            month: Month::fromString('2026-11'),
            enteredMonths: [Month::fromString('2026-11')],
            accountCount: 3,
            hasHousehold: true,
        );

        self::assertSame(
            [FirstStep::Household, FirstStep::Accounts, FirstStep::MonthEntered],
            array_values(array_filter(FirstStep::cases(), static fn(FirstStep $step): bool => $step->isDone($facts))),
        );
        $result = Challenge::FirstSteps->evaluate($facts);
        self::assertSame([3, 5], [$result->stars(), $result->maxStars()]);
    }

    public function testTheStageIsTheHighestRungWhoseRungsBelowAreAllMet(): void
    {
        $month = Month::fromString('2026-11');
        self::assertNull(SaverStage::of(new GameFacts($month)));
        $entered = [Month::fromString('2026-10'), $month];
        self::assertSame(SaverStage::Discovery, SaverStage::of(new GameFacts($month, $entered, completeMonths: 2)));
        self::assertSame(SaverStage::Organized, SaverStage::of(new GameFacts($month, $entered, completeMonths: 3, precautionMonths: 2, precautionTarget: 3)));
        // Without consumer loan but below the emergency savings target: still "Organized", the ladder is climbed in order.
        self::assertSame(SaverStage::Organized, SaverStage::of(new GameFacts($month, $entered, completeMonths: 3, precautionMonths: 2, precautionTarget: 3, consumerCreditFreeMonths: 5)));
        self::assertSame(SaverStage::Serene, SaverStage::of(new GameFacts($month, $entered, completeMonths: 3, precautionMonths: 3, precautionTarget: 3, consumerCreditFreeMonths: 5)));
        self::assertSame(SaverStage::Builder, SaverStage::Serene->next());
        self::assertNull(SaverStage::Builder->next());
    }

    public function testLevelsFromTheStarsEarned(): void
    {
        self::assertSame(28, SaverLevel::maxStars(), 'five first steps, seven challenges of three stars, two passkeys');
        $level = SaverLevel::of(15);
        self::assertSame([4, 'Prévoyant', 18, 3, 40], [$level->number, $level->name, $level->next, $level->missing(), $level->percent()]);
        $last = SaverLevel::of(SaverLevel::maxStars());
        self::assertSame([SaverLevel::count(), null, null, 100], [$last->number, $last->next, $last->missing(), $last->percent()]);
        self::assertSame(1, SaverLevel::of(0)->number);
    }

    public function testEveryStyleOrdersEveryChallengeButTheFirstSteps(): void
    {
        $expected = array_values(array_filter(Challenge::cases(), static fn(Challenge $challenge): bool => $challenge !== Challenge::FirstSteps));
        foreach (SaverStyle::cases() as $style) {
            self::assertSame(self::sorted($expected), self::sorted($style->order()), $style->value);
        }
        self::assertSame(self::sorted($expected), self::sorted(SaverStyle::defaultOrder()));
        self::assertSame(Challenge::Goal, SaverStyle::Project->order()[0]);
    }

    /**
     * @param list<Challenge> $challenges
     *
     * @return list<string>
     */
    private static function sorted(array $challenges): array
    {
        $values = array_map(static fn(Challenge $challenge): string => $challenge->value, $challenges);
        sort($values);

        return $values;
    }
}
