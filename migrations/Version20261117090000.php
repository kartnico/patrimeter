<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Monthly email reminder to enter the balances, chosen by each user: day of the month, language of the email, month of
 * the last sending, and fingerprint of the unsubscribe link of the last email (never the link itself). A child table of
 * users only: dropping it cascades nothing.
 */
final class Version20261117090000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Rappel mensuel de saisie par e-mail';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE entry_reminders (
                user_id          TEXT NOT NULL PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                day              INTEGER NOT NULL CHECK (day BETWEEN 1 AND 28),
                locale           TEXT NOT NULL CHECK (locale IN ('fr', 'en')),
                last_sent_month  TEXT,
                unsubscribe_hash TEXT
            ) WITHOUT ROWID
            SQL);
        $this->addSql('CREATE UNIQUE INDEX entry_reminders_unsubscribe ON entry_reminders(unsubscribe_hash)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE entry_reminders');
    }
}
