<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Budget mensuel du foyer : foyer (prénoms, cible de précaution), catégories, postes et montants de chaque mois,
 * tous chiffrés avec la clé de données de l'utilisateur. Restent en clair : le propriétaire, la catégorie d'un poste
 * et les mois enregistrés. Les clés étrangères composites refusent un poste rangé dans la catégorie d'un autre
 * utilisateur ; tout disparaît avec le compte de l'utilisateur.
 */
final class Version20261113100000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Budget mensuel du foyer';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE budget_households (
                user_id    TEXT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                ciphertext TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
            ) WITHOUT ROWID
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE budget_categories (
                id         INTEGER PRIMARY KEY,
                user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                ciphertext TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                UNIQUE (id, user_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE budget_lines (
                id          INTEGER PRIMARY KEY,
                user_id     TEXT NOT NULL,
                category_id INTEGER NOT NULL,
                ciphertext  TEXT NOT NULL,
                created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                UNIQUE (id, user_id),
                FOREIGN KEY (category_id, user_id) REFERENCES budget_categories(id, user_id) ON DELETE CASCADE
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE budget_months (
                user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                month      TEXT NOT NULL CHECK (month GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]'),
                ciphertext TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                PRIMARY KEY (user_id, month)
            ) WITHOUT ROWID
            SQL);
        $this->addSql('CREATE INDEX budget_categories_user ON budget_categories(user_id)');
        $this->addSql('CREATE INDEX budget_lines_user ON budget_lines(user_id)');
        $this->addSql('CREATE INDEX budget_lines_category ON budget_lines(category_id, user_id)');
    }

    /** Les postes d'abord : supprimer les catégories en premier déclencherait leurs ON DELETE CASCADE. */
    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE budget_lines');
        $this->addSql('DROP TABLE budget_categories');
        $this->addSql('DROP TABLE budget_months');
        $this->addSql('DROP TABLE budget_households');
    }
}
