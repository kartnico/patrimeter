<?php

declare(strict_types=1);

namespace Patrimeter\Domain\Goal;

/**
 * Where a savings goal stands for a month (GoalProgress): the pace of the last months compared with the effort still
 * needed each month. Always shown in words, never by the colour alone.
 */
enum GoalStatus: string
{
    /** Target amount reached. */
    case Reached = 'reached';
    /** Pace at least 20 % above the monthly effort needed. */
    case Ahead = 'ahead';
    /** Pace at least equal to the monthly effort needed. */
    case OnTrack = 'on_track';
    /** Pace between 80 % and 100 % of the effort needed. */
    case Watch = 'watch';
    /** Pace below 80 % of the effort needed, or target month passed without reaching the target. */
    case Late = 'late';
    /** Not one month of history since the start of the goal: no pace to compare yet. */
    case TooEarly = 'too_early';

    public function label(): string
    {
        return match ($this) {
            self::Reached => 'Atteint',
            self::Ahead => 'En avance',
            self::OnTrack => 'Sur la bonne voie',
            self::Watch => 'À surveiller',
            self::Late => 'En retard',
            self::TooEarly => 'Trop tôt pour juger',
        };
    }

    /** Situation that calls for more savings. */
    public function isAlert(): bool
    {
        return $this === self::Watch || $this === self::Late;
    }
}
