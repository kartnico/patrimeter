<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Lien de chaque utilisateur avec son instance Carbure (dépenses réelles face au budget prévu) : adresse, jeton d'API
 * et correspondance des catégories, chiffrés avec la clé de données de l'utilisateur. Reste en clair : le fait qu'un
 * utilisateur a configuré un lien. Tout disparaît avec le compte de l'utilisateur.
 */
final class Version20261113220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Lien avec une instance Carbure';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE carbure_links (
                user_id    TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                ciphertext TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            ) WITHOUT ROWID
            SQL);
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE carbure_links');
    }
}
