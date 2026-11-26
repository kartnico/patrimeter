<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Savings rate challenge (3.1.0): the savings rate aimed at, chosen by the user (whole percent, NULL until chosen: no
 * default, no advice). A preference, without any amount: in clear, like the other game settings.
 */
final class Version20261124090000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Défi du taux d\'épargne : taux visé, choisi par l\'utilisateur';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_settings ADD COLUMN savings_rate_target INTEGER CHECK (savings_rate_target IS NULL OR savings_rate_target BETWEEN 1 AND 90)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE game_settings DROP COLUMN savings_rate_target');
    }
}
