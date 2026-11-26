<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Identités OAuth2/OIDC liées aux comptes : un couple (fournisseur, sujet) désigne un seul compte. */
final class Version20261109150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'OAuth2/OIDC : identités liées aux comptes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_identities (
                provider   TEXT NOT NULL,
                subject    TEXT NOT NULL,
                user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                email      TEXT,
                created_at TEXT NOT NULL,
                PRIMARY KEY (provider, subject)
            ) WITHOUT ROWID
            SQL);
        $this->addSql('CREATE INDEX oauth_identities_user ON oauth_identities(user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE oauth_identities');
    }
}
