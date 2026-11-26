<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Challenges and stars (3.0.0): the game settings of each user (game shown or not, declared saver style, first-steps card
 * hidden) and, per challenge, the best number of stars reached and the day it was reached. Stars are recomputed from the
 * encrypted data at each visit; this table only keeps the best result, so that a star earned is never lost. Neither
 * table holds an amount: they are stored in clear, like the other preferences.
 */
final class Version20261123080000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Défis et étoiles : réglages du jeu et meilleures étoiles de chaque défi';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE game_settings (
                user_id            TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                enabled            INTEGER NOT NULL DEFAULT 1 CHECK (enabled IN (0, 1)),
                saver_style        TEXT CHECK (saver_style IS NULL OR saver_style IN ('ant', 'project', 'home', 'horizon')),
                first_steps_hidden INTEGER NOT NULL DEFAULT 0 CHECK (first_steps_hidden IN (0, 1))
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE game_stars (
                user_id   TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                challenge TEXT NOT NULL CHECK (length(challenge) BETWEEN 1 AND 40),
                stars     INTEGER NOT NULL CHECK (stars BETWEEN 1 AND 6),
                earned_on TEXT NOT NULL CHECK (earned_on GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]-[0-3][0-9]'),
                PRIMARY KEY (user_id, challenge)
            )
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE game_stars');
        $this->addSql('DROP TABLE game_settings');
    }
}
