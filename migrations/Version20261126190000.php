<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126190000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Retryable monthly reminder delivery';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry_reminders ADD claim_hash TEXT');
        $this->addSql('ALTER TABLE entry_reminders ADD claim_until INTEGER');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE entry_reminders DROP COLUMN claim_until');
        $this->addSql('ALTER TABLE entry_reminders DROP COLUMN claim_hash');
    }
}
