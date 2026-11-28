<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Span of the active accounts kept with the monthly reminder (months only, no amount)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        // Unknown until the next account change or reminder choice: the reminder keeps leaving as before.
        $this->addSql('ALTER TABLE entry_reminders ADD activity_known INTEGER NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE entry_reminders ADD active_from TEXT');
        $this->addSql('ALTER TABLE entry_reminders ADD active_until TEXT');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry_reminders DROP COLUMN active_until');
        $this->addSql('ALTER TABLE entry_reminders DROP COLUMN active_from');
        $this->addSql('ALTER TABLE entry_reminders DROP COLUMN activity_known');
    }
}
