<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Table settings retirée : héritée de la v1 (mot de passe unique), elle n'est plus ni lue ni écrite depuis le
 * passage au multi-utilisateurs. down() la recrée vide, pour que les migrations plus anciennes restent
 * réversibles.
 */
final class Version20261110140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Suppression de la table settings, inutilisée';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE settings');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
    }
}
