<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Chiffrement des données patrimoniales : enveloppes de la clé de données de chaque utilisateur
 * (`key_envelopes`) ; les comptes et les relevés ne contiennent plus que des chiffrés.
 *
 * Installation neuve uniquement : aucune conversion de données en clair n'est prévue, la
 * migration refuse une base qui contient déjà des comptes.
 */
final class Version20261109090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Chiffrement : enveloppes de clés, comptes et relevés chiffrés';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->fetchOne('SELECT 1 FROM accounts LIMIT 1') !== false,
            'Cette base contient des comptes en clair : repartir d\'une base neuve (aucune conversion n\'est prévue).',
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE key_envelopes (
                user_id       TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type          TEXT NOT NULL,
                credential_id TEXT NOT NULL DEFAULT '',
                salt          TEXT NOT NULL,
                ops_limit     INTEGER,
                mem_limit     INTEGER,
                wrapped_key   TEXT NOT NULL,
                created_at    TEXT NOT NULL,
                PRIMARY KEY (user_id, type, credential_id)
            ) WITHOUT ROWID
            SQL);

        // Tables vides : l'enfant d'abord, aucune cascade à craindre.
        $this->addSql('DROP TABLE statements');
        $this->addSql('DROP TABLE accounts');
        $this->addSql(<<<'SQL'
            CREATE TABLE accounts (
                id         INTEGER PRIMARY KEY,
                user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                ciphertext TEXT NOT NULL,
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                UNIQUE (id, user_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE statements (
                account_id INTEGER NOT NULL,
                user_id    TEXT NOT NULL,
                month      TEXT NOT NULL CHECK (month GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]'),
                ciphertext TEXT NOT NULL,
                updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                PRIMARY KEY (account_id, month),
                FOREIGN KEY (account_id, user_id) REFERENCES accounts(id, user_id) ON DELETE CASCADE
            ) WITHOUT ROWID
            SQL);
        $this->addSql('CREATE INDEX accounts_user ON accounts(user_id)');
        $this->addSql('CREATE INDEX statements_user_month ON statements(user_id, month)');
    }

    /** Impossible dès qu'une donnée est chiffrée : sans les clés des utilisateurs, rien ne peut être remis en clair. */
    public function down(Schema $schema): void
    {
        $this->abortIf(
            $this->connection->fetchOne('SELECT 1 FROM accounts LIMIT 1') !== false,
            'Des données sont chiffrées : sans les clés des utilisateurs, elles ne peuvent pas être remises en clair.',
        );

        $this->addSql('DROP TABLE statements');
        $this->addSql('DROP TABLE accounts');
        $this->addSql(<<<'SQL'
            CREATE TABLE accounts (
                id         INTEGER PRIMARY KEY,
                user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                name       TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
                type_id    INTEGER NOT NULL REFERENCES account_types(id),
                level_code TEXT NOT NULL REFERENCES levels(code),
                nature     TEXT NOT NULL CHECK (nature IN ('asset', 'liability')),
                opened_on  TEXT NOT NULL CHECK (opened_on GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]-[0-3][0-9]'),
                closed_on  TEXT CHECK (closed_on IS NULL OR closed_on GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]-[0-3][0-9]'),
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                CHECK (closed_on IS NULL OR closed_on >= opened_on),
                UNIQUE (id, user_id)
            )
            SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE statements (
                account_id   INTEGER NOT NULL,
                user_id      TEXT NOT NULL,
                month        TEXT NOT NULL CHECK (month GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]'),
                amount_cents INTEGER NOT NULL,
                updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                PRIMARY KEY (account_id, month),
                FOREIGN KEY (account_id, user_id) REFERENCES accounts(id, user_id) ON DELETE CASCADE
            ) WITHOUT ROWID
            SQL);
        $this->addSql('CREATE INDEX accounts_user ON accounts(user_id, level_code)');
        $this->addSql('CREATE INDEX statements_user_month ON statements(user_id, month)');
        $this->addSql('DROP TABLE key_envelopes');
    }
}
