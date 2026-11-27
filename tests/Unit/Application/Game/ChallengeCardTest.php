<?php

declare(strict_types=1);

namespace Patrimeter\Tests\Unit\Application\Game;

use Patrimeter\Application\Game\ChallengeCard;
use Patrimeter\Application\Game\GameView;
use Patrimeter\Domain\Game\Challenge;
use Patrimeter\Domain\Game\ChallengeFamily;
use Patrimeter\Domain\Game\ChallengeResult;
use Patrimeter\Domain\Game\FirstStep;
use Patrimeter\Domain\Game\GameFacts;
use Patrimeter\Domain\Game\SaverLevel;
use Patrimeter\Domain\Game\SaverStage;
use Patrimeter\Domain\Month;
use PHPUnit\Framework\TestCase;

/** The Défis page as GameView prepares it: cards of the challenges, stars by family, the next stage. */
final class ChallengeCardTest extends TestCase
{
    public function testTheRailAndWhatIsMissingFollowTheKeptStars(): void
    {
        $facts = new GameFacts(Month::fromString('2026-11'), completeMonths: 2);
        // Two stars kept from earlier months, while only two months are complete now.
        $card = self::view($facts, ['up_to_date' => 2])->card(Challenge::UpToDate->evaluate($facts));

        self::assertSame(
            [['threshold' => 1, 'state' => ChallengeCard::EARNED], ['threshold' => 6, 'state' => ChallengeCard::EARNED], ['threshold' => 12, 'state' => ChallengeCard::NEXT]],
            $card->steps(),
        );
        self::assertSame(3, $card->nextRank());
        self::assertSame('Pour la 3ᵉ étoile', $card->nextStarLabel());
        self::assertSame(10, $card->missing(), 'from this month\'s value up to the third threshold');
        self::assertSame(12, $card->missingParameters()['%next%']);
        self::assertFalse($card->isComplete());
    }

    public function testACompleteOrUnmeasurableChallengeHasNothingMissing(): void
    {
        $facts = new GameFacts(Month::fromString('2026-11'), passkeys: 2);
        $view = self::view($facts, ['security' => 2]);

        $security = $view->card(Challenge::Security->evaluate($facts));
        self::assertTrue($security->isComplete());
        self::assertNull($security->nextRank());
        self::assertNull($security->nextStarLabel());
        self::assertNull($security->missing());

        $goal = $view->card(Challenge::Goal->evaluate($facts));
        self::assertNull($goal->missing(), 'no goal: the card says what to do first');
        self::assertSame('Pour la 1ʳᵉ étoile', $goal->nextStarLabel());
    }

    public function testFirstStepsListTheStepsLeft(): void
    {
        $facts = new GameFacts(Month::fromString('2026-11'), accountCount: 2, hasHousehold: true);
        $card = self::view($facts, ['first_steps' => 2])->card(Challenge::FirstSteps->evaluate($facts));

        self::assertSame(1, $card->missing());
        self::assertSame([FirstStep::MonthEntered, FirstStep::Property, FirstStep::Goal], $card->remainingSteps);
    }

    public function testStarsByFamilyAddUpToTheTotal(): void
    {
        $facts = new GameFacts(Month::fromString('2026-11'));
        $view = self::view($facts, ['streak' => 2, 'up_to_date' => 1, 'security' => 1]);

        $families = $view->families();
        self::assertSame(ChallengeFamily::cases(), array_column($families, 'family'));
        self::assertSame([5, 6, 9, 6, 2], array_column($families, 'max'));
        self::assertSame(SaverLevel::maxStars(), array_sum(array_column($families, 'max')));
        self::assertSame([0, 3, 0, 0, 1], array_column($families, 'stars'));
        self::assertSame($view->totalStars(), array_sum(array_column($families, 'stars')));
        self::assertSame(SaverLevel::maxStars() - 4, $view->remainingStars());
    }

    public function testTheNextStageAndHowFarItIs(): void
    {
        $none = self::view(new GameFacts(Month::fromString('2026-11')), []);
        self::assertNull($none->stageNumber());
        self::assertSame(SaverStage::Discovery, $none->nextStage());
        self::assertSame(['value' => 0, 'target' => 1], $none->nextStageMeasure());

        $month = Month::fromString('2026-11');
        $organized = self::view(new GameFacts($month, enteredMonths: [Month::fromString('2026-10'), $month], completeMonths: 4, precautionMonths: 2, precautionTarget: 6), []);
        self::assertSame(2, $organized->stageNumber());
        self::assertSame(SaverStage::Protected, $organized->nextStage());
        self::assertSame(['value' => 2, 'target' => 6], $organized->nextStageMeasure());
        self::assertNull(SaverStage::Serene->measure(new GameFacts($month)), 'a yes or no condition has no bar');
        self::assertNull(SaverStage::Protected->measure(new GameFacts($month)), 'no budget: nothing to count yet');
    }

    /** @param array<string, int> $best */
    private static function view(GameFacts $facts, array $best): GameView
    {
        $results = array_map(static fn(Challenge $challenge): ChallengeResult => $challenge->evaluate($facts), Challenge::cases());

        return new GameView($facts, $results, $best, null, false);
    }
}
