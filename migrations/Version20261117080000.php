<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Order of the budget's categories and items, chosen by the user: a position in clear (only the order, like the number
 * of rows), the order of creation for the existing rows.
 */
final class Version20261117080000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Ordre des catégories et des postes du budget';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_categories ADD COLUMN position INTEGER NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE budget_lines ADD COLUMN position INTEGER NOT NULL DEFAULT 0');
        $this->addSql('UPDATE budget_categories SET position = id');
        $this->addSql('UPDATE budget_lines SET position = id');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE budget_lines DROP COLUMN position');
        $this->addSql('ALTER TABLE budget_categories DROP COLUMN position');
    }
}
