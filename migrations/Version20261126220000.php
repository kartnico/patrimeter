<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Remember unannounced challenge stars without replaying existing awards';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_stars ADD announcement_pending INTEGER NOT NULL DEFAULT 0');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_stars DROP COLUMN announcement_pending');
    }
}
