<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Demonstration accounts (DEMO_MODE): the users created by the "Try the demo" button, with the date after which they
 * are deleted. A child table of users only: dropping it cascades nothing (the demonstration users stay, as ordinary
 * accounts without a password).
 */
final class Version20261116140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Comptes de démonstration temporaires';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE demo_accounts (
                user_id    TEXT NOT NULL PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                expires_at TEXT NOT NULL
            ) WITHOUT ROWID
            SQL);
        $this->addSql('CREATE INDEX demo_accounts_expiry ON demo_accounts(expires_at)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE demo_accounts');
    }
}
