<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Trusted browsers: the second factor is not asked again for 30 days on a browser where the user ticked "trust this
 * browser". Only the fingerprint of the cookie's secret is kept, with the fingerprint of the credentials at that time
 * (a new password or signed-out sessions make the trust fall). A child table of users only: dropping it cascades
 * nothing.
 */
final class Version20261118080000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Navigateurs de confiance (second facteur demandé moins souvent)';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE trusted_browsers (
                id            TEXT NOT NULL PRIMARY KEY,
                user_id       TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                verifier_hash TEXT NOT NULL,
                fingerprint   TEXT NOT NULL,
                ip            TEXT,
                user_agent    TEXT,
                created_at    TEXT NOT NULL,
                last_used_at  TEXT,
                expires_at    TEXT NOT NULL
            ) WITHOUT ROWID
            SQL);
        $this->addSql('CREATE INDEX trusted_browsers_user ON trusted_browsers(user_id, expires_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trusted_browsers');
    }
}
