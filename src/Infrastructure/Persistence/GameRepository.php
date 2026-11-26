<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Patrimeter\Domain\Game\Challenge;
use Patrimeter\Domain\Game\SaverStyle;
use Patrimeter\Domain\UserId;

/**
 * Challenges and stars of a user (`game_settings`, `game_stars` tables): game shown or not, declared saver style,
 * first-steps card hidden, and the best stars reached by each challenge. No amount: stored in clear.
 */
final class GameRepository
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * @return array{enabled: bool, style: ?SaverStyle, first_steps_hidden: bool, savings_rate_target: ?int} defaults when
     *                                                                                                         nothing is stored
     */
    public function settings(UserId $owner): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT enabled, saver_style, first_steps_hidden, savings_rate_target FROM game_settings WHERE user_id = ?',
            [$owner->value],
        );
        if ($row === false) {
            return ['enabled' => true, 'style' => null, 'first_steps_hidden' => false, 'savings_rate_target' => null];
        }
        $style = Row::nullableString($row, 'saver_style');

        return [
            'enabled' => Row::int($row, 'enabled') === 1,
            'style' => $style === null ? null : SaverStyle::tryFrom($style),
            'first_steps_hidden' => Row::int($row, 'first_steps_hidden') === 1,
            'savings_rate_target' => Row::nullableInt($row, 'savings_rate_target'),
        ];
    }

    public function saveEnabled(UserId $owner, bool $enabled): void
    {
        $this->connection->executeStatement(
            'INSERT INTO game_settings (user_id, enabled) VALUES (?, ?)
             ON CONFLICT(user_id) DO UPDATE SET enabled = excluded.enabled',
            [$owner->value, (int) $enabled],
        );
    }

    public function saveStyle(UserId $owner, ?SaverStyle $style): void
    {
        $this->connection->executeStatement(
            'INSERT INTO game_settings (user_id, saver_style) VALUES (?, ?)
             ON CONFLICT(user_id) DO UPDATE SET saver_style = excluded.saver_style',
            [$owner->value, $style?->value],
        );
    }

    public function saveFirstStepsHidden(UserId $owner, bool $hidden): void
    {
        $this->connection->executeStatement(
            'INSERT INTO game_settings (user_id, first_steps_hidden) VALUES (?, ?)
             ON CONFLICT(user_id) DO UPDATE SET first_steps_hidden = excluded.first_steps_hidden',
            [$owner->value, (int) $hidden],
        );
    }

    /** Savings rate aimed at, in whole percent (1 to 90); null: none chosen. */
    public function saveSavingsRateTarget(UserId $owner, ?int $percent): void
    {
        $this->connection->executeStatement(
            'INSERT INTO game_settings (user_id, savings_rate_target) VALUES (?, ?)
             ON CONFLICT(user_id) DO UPDATE SET savings_rate_target = excluded.savings_rate_target',
            [$owner->value, $percent],
        );
    }

    /** @return array<string, int> best stars reached, by challenge (value of Challenge) */
    public function stars(UserId $owner): array
    {
        $stars = [];
        foreach ($this->connection->fetchAllAssociative('SELECT challenge, stars FROM game_stars WHERE user_id = ?', [$owner->value]) as $row) {
            $stars[Row::string($row, 'challenge')] = Row::int($row, 'stars');
        }

        return $stars;
    }

    /** Keeps the stars of a challenge if they beat the best ones stored (never lowers them). */
    public function raise(UserId $owner, Challenge $challenge, int $stars, string $today): void
    {
        $this->connection->executeStatement(
            'INSERT INTO game_stars (user_id, challenge, stars, earned_on) VALUES (?, ?, ?, ?)
             ON CONFLICT(user_id, challenge) DO UPDATE SET stars = excluded.stars, earned_on = excluded.earned_on
             WHERE excluded.stars > game_stars.stars',
            [$owner->value, $challenge->value, $stars, $today],
        );
    }
}
