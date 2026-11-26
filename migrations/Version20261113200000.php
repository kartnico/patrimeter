<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Compte famille : contenu du dernier fichier d'export importé pour chacun de ses membres (deux au plus), chiffré avec
 * la clé de données du compte famille. Restent en clair : le propriétaire et le nombre de membres ; tout disparaît avec
 * le compte de l'utilisateur.
 */
final class Version20261113200000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Compte famille : membres importés';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE family_members (
                id         INTEGER PRIMARY KEY,
                user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                ciphertext TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                UNIQUE (id, user_id)
            )
            SQL);
        $this->addSql('CREATE INDEX family_members_user ON family_members(user_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE family_members');
    }
}
