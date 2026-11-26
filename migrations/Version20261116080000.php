<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Signed-in sessions of each user, listed on the sign-in and security tab: a random identifier kept in the session
 * (never the PHP session identifier), the address and browser seen at the start, the start and the last activity, and
 * the date it was signed out from another device. A child table of users only: dropping it cascades nothing.
 */
final class Version20261116080000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Sessions ouvertes de chaque utilisateur';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_sessions (
                id           TEXT NOT NULL PRIMARY KEY,
                user_id      TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                ip           TEXT,
                user_agent   TEXT,
                created_at   TEXT NOT NULL,
                last_seen_at TEXT NOT NULL,
                revoked_at   TEXT
            ) WITHOUT ROWID
            SQL);
        $this->addSql('CREATE INDEX user_sessions_user ON user_sessions(user_id, last_seen_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_sessions');
    }
}
