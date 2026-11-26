<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Approximate balances (3.1.0): the balances of the first accounts, entered on the welcome pages (often a range or a
 * rough figure), flagged "to refine" until the user enters the balance again. The table holds no amount, only which
 * statement is approximate: it is stored in clear, like the months of the statements. Deleting the statement deletes
 * its flag.
 */
final class Version20261124080000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Soldes approximatifs des premiers comptes, à affiner';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE approximate_statements (
                user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                account_id INTEGER NOT NULL,
                month      TEXT NOT NULL,
                PRIMARY KEY (account_id, month),
                FOREIGN KEY (account_id, month) REFERENCES statements(account_id, month) ON DELETE CASCADE
            ) WITHOUT ROWID
            SQL);
        $this->addSql('CREATE INDEX approximate_statements_user ON approximate_statements(user_id)');
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE approximate_statements');
    }
}
