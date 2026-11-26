<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Multi-utilisateurs : utilisateurs, codes de secours, jetons à usage unique, journal des
 * connexions ; chaque compte et chaque relevé appartient à un utilisateur.
 *
 * Cloisonnement en base : `statements (account_id, user_id)` référence `accounts (id, user_id)`,
 * si bien qu'un relevé ne peut pas pointer vers le compte d'un autre utilisateur.
 *
 * Base existante (v1) : ses comptes et son mot de passe sont rattachés à un premier utilisateur
 * sans adresse e-mail, que `bin/console app:legacy:assign-owner` complète ensuite.
 *
 * Reconstruction des tables sans cascade parasite : avec les clés étrangères actives, un
 * DROP TABLE supprime d'abord les lignes, ce qui déclencherait les ON DELETE CASCADE. Les
 * nouvelles tables sont donc remplies d'abord, puis l'ancienne table des relevés (enfant) est
 * supprimée avant celle des comptes (parent), qui n'a alors plus aucune ligne qui la référence.
 */
final class Version20261108120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Multi-utilisateurs : utilisateurs, authentification, journal, propriétaire de chaque compte et relevé';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE users (
                id                TEXT PRIMARY KEY CHECK (length(id) = 36),
                email             TEXT UNIQUE CHECK (email IS NULL OR (length(email) BETWEEN 3 AND 254 AND email = lower(email))),
                password_hash     TEXT,
                email_verified_at TEXT,
                totp_secret       TEXT,
                totp_enabled_at   TEXT,
                session_stamp     TEXT NOT NULL,
                created_at        TEXT NOT NULL,
                CHECK (email_verified_at IS NULL OR email IS NOT NULL),
                CHECK (totp_enabled_at IS NULL OR totp_secret IS NOT NULL)
            )
            SQL);

        // Codes de secours : empreinte HMAC uniquement (clé serveur), jamais le code lui-même.
        $this->addSql(<<<'SQL'
            CREATE TABLE backup_codes (
                user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                code_hash  TEXT NOT NULL,
                used_at    TEXT,
                created_at TEXT NOT NULL,
                PRIMARY KEY (user_id, code_hash)
            ) WITHOUT ROWID
            SQL);

        // Jetons envoyés par e-mail : sélecteur en clair, empreinte SHA-256 du vérificateur.
        $this->addSql(<<<'SQL'
            CREATE TABLE one_time_tokens (
                selector      TEXT PRIMARY KEY,
                user_id       TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                purpose       TEXT NOT NULL,
                verifier_hash TEXT NOT NULL,
                expires_at    TEXT NOT NULL,
                used_at       TEXT,
                created_at    TEXT NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX one_time_tokens_user ON one_time_tokens(user_id, purpose)');

        $this->addSql(<<<'SQL'
            CREATE TABLE auth_events (
                id         INTEGER PRIMARY KEY,
                user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type       TEXT NOT NULL,
                success    INTEGER NOT NULL CHECK (success IN (0, 1)),
                ip         TEXT,
                user_agent TEXT,
                created_at TEXT NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX auth_events_user ON auth_events(user_id, created_at)');

        $owner = $this->legacyOwner();
        if ($owner !== null) {
            $this->addSql(
                "INSERT INTO users (id, email, password_hash, session_stamp, created_at)
                 VALUES (?, NULL, ?, ?, strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))",
                [$owner['id'], $owner['password_hash'], bin2hex(random_bytes(16))],
            );
        }
        $this->addSql("DELETE FROM settings WHERE key = 'password_hash'");

        $this->addSql(<<<'SQL'
            CREATE TABLE accounts_new (
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
        if ($owner !== null) {
            $this->addSql(
                'INSERT INTO accounts_new (id, user_id, name, type_id, level_code, nature, opened_on, closed_on, created_at)
                 SELECT id, ?, name, type_id, level_code, nature, opened_on, closed_on, created_at FROM accounts',
                [$owner['id']],
            );
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE statements_new (
                account_id   INTEGER NOT NULL,
                user_id      TEXT NOT NULL,
                month        TEXT NOT NULL CHECK (month GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]'),
                amount_cents INTEGER NOT NULL,
                updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                PRIMARY KEY (account_id, month),
                FOREIGN KEY (account_id, user_id) REFERENCES accounts_new(id, user_id) ON DELETE CASCADE
            ) WITHOUT ROWID
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO statements_new (account_id, user_id, month, amount_cents, updated_at)
            SELECT s.account_id, a.user_id, s.month, s.amount_cents, s.updated_at
            FROM statements s JOIN accounts_new a ON a.id = s.account_id
            SQL);

        $this->replaceTables();
        $this->addSql('CREATE INDEX accounts_user ON accounts(user_id, level_code)');
        $this->addSql('CREATE INDEX statements_user_month ON statements(user_id, month)');
    }

    public function postUp(Schema $schema): void
    {
        $this->assertForeignKeysHold();
    }

    /**
     * Retour au schéma mono-utilisateur, possible tant qu'un seul utilisateur existe : ses
     * comptes, ses relevés et son mot de passe sont conservés ; e-mail, TOTP, codes de secours
     * et journal sont perdus.
     */
    public function down(Schema $schema): void
    {
        $users = $this->connection->fetchAllAssociative('SELECT id, password_hash FROM users');
        $this->abortIf(count($users) > 1, 'Plusieurs utilisateurs existent : revenir au schéma mono-utilisateur mélangerait leurs données.');

        $passwordHash = $users[0]['password_hash'] ?? null;
        if (is_string($passwordHash)) {
            $this->addSql("INSERT INTO settings (key, value) VALUES ('password_hash', ?)", [$passwordHash]);
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE accounts_new (
                id         INTEGER PRIMARY KEY,
                name       TEXT NOT NULL CHECK (length(name) BETWEEN 1 AND 80),
                type_id    INTEGER NOT NULL REFERENCES account_types(id),
                level_code TEXT NOT NULL REFERENCES levels(code),
                nature     TEXT NOT NULL CHECK (nature IN ('asset', 'liability')),
                opened_on  TEXT NOT NULL CHECK (opened_on GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]-[0-3][0-9]'),
                closed_on  TEXT CHECK (closed_on IS NULL OR closed_on GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]-[0-3][0-9]'),
                created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                CHECK (closed_on IS NULL OR closed_on >= opened_on)
            )
            SQL);
        $this->addSql(
            'INSERT INTO accounts_new (id, name, type_id, level_code, nature, opened_on, closed_on, created_at)
             SELECT id, name, type_id, level_code, nature, opened_on, closed_on, created_at FROM accounts',
        );
        $this->addSql(<<<'SQL'
            CREATE TABLE statements_new (
                account_id   INTEGER NOT NULL REFERENCES accounts_new(id) ON DELETE CASCADE,
                month        TEXT NOT NULL CHECK (month GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]'),
                amount_cents INTEGER NOT NULL,
                updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                PRIMARY KEY (account_id, month)
            ) WITHOUT ROWID
            SQL);
        $this->addSql(
            'INSERT INTO statements_new (account_id, month, amount_cents, updated_at)
             SELECT account_id, month, amount_cents, updated_at FROM statements',
        );

        $this->replaceTables();
        $this->addSql('CREATE INDEX accounts_level ON accounts(level_code)');
        $this->addSql('CREATE INDEX statements_month ON statements(month)');

        // Tables enfants d'abord : supprimer `users` en premier viderait les autres par cascade.
        foreach (['auth_events', 'one_time_tokens', 'backup_codes', 'users'] as $table) {
            $this->addSql("DROP TABLE $table");
        }
    }

    public function postDown(Schema $schema): void
    {
        $this->assertForeignKeysHold();
    }

    /**
     * Premier utilisateur à créer pour une base existante : s'il y a des comptes ou un mot de
     * passe. Installation neuve : aucun.
     *
     * @return array{id: string, password_hash: string|null}|null
     */
    private function legacyOwner(): ?array
    {
        $hasAccounts = $this->connection->fetchOne('SELECT 1 FROM accounts LIMIT 1') !== false;
        $hash = $this->connection->fetchOne("SELECT value FROM settings WHERE key = 'password_hash'");
        if (!$hasAccounts && !is_string($hash)) {
            return null;
        }

        return ['id' => Uuid::v7()->toRfc4122(), 'password_hash' => is_string($hash) ? $hash : null];
    }

    /** Annule toute la migration (transaction) si une clé étrangère est violée après reconstruction. */
    private function assertForeignKeysHold(): void
    {
        $violations = $this->connection->fetchAllAssociative('PRAGMA foreign_key_check');
        $this->abortIf($violations !== [], sprintf('%d ligne(s) violent une clé étrangère : migration annulée.', count($violations)));
    }

    /** Remplace `accounts` et `statements` par leurs versions `_new`, dans l'ordre sans cascade. */
    private function replaceTables(): void
    {
        $this->addSql('DROP TABLE statements');
        $this->addSql('DROP TABLE accounts');
        $this->addSql('ALTER TABLE accounts_new RENAME TO accounts');
        $this->addSql('ALTER TABLE statements_new RENAME TO statements');
    }
}
