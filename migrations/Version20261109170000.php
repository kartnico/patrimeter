<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Quotes-parts d'un bien : pourcentage chiffré, par mois d'effet. Le lien entre un crédit et son
 * bien est dans les données chiffrées du compte, pas dans le schéma.
 */
final class Version20261109170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Immobilier : historique des quotes-parts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ownership_shares (
                account_id      INTEGER NOT NULL,
                user_id         TEXT NOT NULL,
                effective_month TEXT NOT NULL CHECK (effective_month GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]'),
                ciphertext      TEXT NOT NULL,
                PRIMARY KEY (account_id, effective_month),
                FOREIGN KEY (account_id, user_id) REFERENCES accounts(id, user_id) ON DELETE CASCADE
            ) WITHOUT ROWID
            SQL);
        $this->addSql('CREATE INDEX ownership_shares_user ON ownership_shares(user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ownership_shares');
    }
}
