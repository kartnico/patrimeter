<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** Passkeys et clés de sécurité FIDO2 : clé publique, compteur, usage et nom (aucun secret). */
final class Version20261109120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'WebAuthn : identifiants de passkeys et de clés de sécurité';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE webauthn_credentials (
                credential_id TEXT PRIMARY KEY,
                user_id       TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                usage         TEXT NOT NULL CHECK (usage IN ('passkey', 'security_key')),
                label         TEXT NOT NULL CHECK (length(label) BETWEEN 1 AND 60),
                record        TEXT NOT NULL,
                created_at    TEXT NOT NULL,
                last_used_at  TEXT
            )
            SQL);
        $this->addSql('CREATE INDEX webauthn_credentials_user ON webauthn_credentials(user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webauthn_credentials');
    }
}
