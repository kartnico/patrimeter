<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Sign-in with an external account (Google, GitHub, OIDC) is removed: its table goes. A child table of users only,
 * so dropping it cascades nothing. down() recreates it empty, as Version20261109150000 created it.
 */
final class Version20261115220000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Retrait de la connexion par compte externe';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE oauth_identities');
    }

    #[\Override]
    public function down(Schema $schema): void
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
}
