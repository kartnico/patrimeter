<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Savings goals of each user: name, target amount and month, perimeter and starting point, encrypted with the user's
 * data key (context tied to the row and to the owner). Left in clear: the number of goals. A child table of users
 * only: dropping it cascades nothing.
 */
final class Version20261116150000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Objectifs d\'épargne';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE savings_goals (
                id         INTEGER PRIMARY KEY,
                user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                ciphertext TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                UNIQUE (id, user_id)
            )
            SQL);
        $this->addSql('CREATE INDEX savings_goals_user ON savings_goals(user_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE savings_goals');
    }
}
