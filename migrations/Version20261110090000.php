<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * API et service MCP : clients OAuth 2.1, codes d'autorisation, autorisations accordées et jetons.
 *
 * Aucun secret en clair : seule l'empreinte des jetons est stockée, et la clé de données n'y figure
 * qu'enveloppée sous une clé dérivée du jeton, que le serveur ne conserve pas.
 */
final class Version20261110090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'API et MCP : clients OAuth, codes, autorisations et jetons';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_clients (
                client_id     TEXT PRIMARY KEY,
                client_name   TEXT NOT NULL CHECK (length(client_name) BETWEEN 1 AND 80),
                redirect_uris TEXT NOT NULL,
                created_at    TEXT NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE oauth_codes (
                selector       TEXT PRIMARY KEY,
                user_id        TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                client_id      TEXT NOT NULL REFERENCES oauth_clients(client_id) ON DELETE CASCADE,
                redirect_uri   TEXT NOT NULL,
                code_challenge TEXT NOT NULL,
                scope          TEXT NOT NULL,
                resource       TEXT NOT NULL,
                verifier_hash  TEXT NOT NULL,
                wrapped_key    TEXT NOT NULL,
                expires_at     TEXT NOT NULL,
                used_at        TEXT
            )
            SQL);
        $this->addSql('CREATE INDEX oauth_codes_expiry ON oauth_codes(expires_at)');
        $this->addSql(<<<'SQL'
            CREATE TABLE api_grants (
                id           TEXT PRIMARY KEY CHECK (length(id) = 36),
                user_id      TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                client_id    TEXT REFERENCES oauth_clients(client_id) ON DELETE CASCADE,
                name         TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
                scope        TEXT NOT NULL,
                resource     TEXT NOT NULL,
                fingerprint  TEXT NOT NULL,
                expires_at   TEXT,
                created_at   TEXT NOT NULL,
                last_used_at TEXT
            )
            SQL);
        $this->addSql('CREATE INDEX api_grants_user ON api_grants(user_id)');
        $this->addSql(<<<'SQL'
            CREATE TABLE api_tokens (
                selector      TEXT PRIMARY KEY,
                grant_id      TEXT NOT NULL REFERENCES api_grants(id) ON DELETE CASCADE,
                kind          TEXT NOT NULL CHECK (kind IN ('access', 'refresh')),
                verifier_hash TEXT NOT NULL,
                wrapped_key   TEXT NOT NULL,
                expires_at    TEXT NOT NULL,
                used_at       TEXT
            )
            SQL);
        $this->addSql('CREATE INDEX api_tokens_grant ON api_tokens(grant_id)');
    }

    public function down(Schema $schema): void
    {
        // Tables enfants d'abord : supprimer un parent déclencherait les ON DELETE CASCADE.
        foreach (['api_tokens', 'api_grants', 'oauth_codes', 'oauth_clients'] as $table) {
            $this->addSql("DROP TABLE $table");
        }
    }
}
