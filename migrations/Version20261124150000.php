<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Custom indicators of each user: name, chosen levels and accounts, share of the net worth or not, encrypted with the
 * user's data key (context tied to the row and to the owner). Left in clear: the number of indicators. A child table
 * of users only: dropping it cascades nothing.
 */
final class Version20261124150000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Indicateurs personnalisés';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE custom_indicators (
                id         INTEGER PRIMARY KEY,
                user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                ciphertext TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                UNIQUE (id, user_id)
            )
            SQL);
        $this->addSql('CREATE INDEX custom_indicators_user ON custom_indicators(user_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE custom_indicators');
    }
}
