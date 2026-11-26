<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Schéma initial (celui de la v1 de Patrimeter).
 *
 * Installation neuve : crée les tables et les référentiels. Base v1 existante (créée par
 * l'ancien bin/migrate.php) : elle est adoptée telle quelle, sans aucune modification.
 * Montants en centimes, mois au format AAAA-MM, dates au format AAAA-MM-JJ.
 */
final class Version20261108000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schéma initial (v1) : niveaux, types de comptes, comptes, relevés, réglages, limitation des connexions';
    }

    public function up(Schema $schema): void
    {
        if ($this->connection->createSchemaManager()->tablesExist(['accounts'])) {
            $this->write('Base v1 existante : schéma adopté sans modification.');

            return;
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE levels (
                code     TEXT PRIMARY KEY,
                label    TEXT NOT NULL,
                tier     INTEGER NOT NULL,
                position INTEGER NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO levels (code, label, tier, position) VALUES
                ('residence',   'Résidence principale',   1, 1),
                ('precaution',  'Épargne de précaution',  2, 2),
                ('fonds_euros', 'Fonds euros',            3, 3),
                ('actions',     'Actions',                4, 4),
                ('immobilier',  'Immobilier',             4, 5),
                ('exotique',    'Exotique',               5, 6)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE account_types (
                id            INTEGER PRIMARY KEY,
                code          TEXT NOT NULL UNIQUE,
                label         TEXT NOT NULL,
                default_level TEXT NOT NULL REFERENCES levels(code),
                nature        TEXT NOT NULL CHECK (nature IN ('asset', 'liability')),
                position      INTEGER NOT NULL
            )
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO account_types (code, label, default_level, nature, position) VALUES
                ('residence_principale', 'Résidence principale (valeur du bien)', 'residence',   'asset',      10),
                ('credit_immo_rp',       'Crédit immobilier résidence principale', 'residence',  'liability',  20),
                ('compte_courant',       'Compte courant',                        'precaution',  'asset',      30),
                ('livret_a',             'Livret A',                              'precaution',  'asset',      40),
                ('ldds',                 'LDDS',                                  'precaution',  'asset',      50),
                ('lep',                  'LEP',                                   'precaution',  'asset',      60),
                ('livret_bancaire',      'Livret bancaire / compte à terme',      'precaution',  'asset',      70),
                ('pel_cel',              'PEL / CEL',                             'precaution',  'asset',      80),
                ('fonds_euros',          'Assurance-vie — fonds euros',           'fonds_euros', 'asset',      90),
                ('av_uc',                'Assurance-vie — unités de compte',      'actions',     'asset',     100),
                ('pea',                  'PEA / PEA-PME',                         'actions',     'asset',     110),
                ('cto',                  'Compte-titres (CTO)',                   'actions',     'asset',     120),
                ('per',                  'PER',                                   'actions',     'asset',     130),
                ('gestion_pilotee',      'Gestion pilotée',                       'actions',     'asset',     140),
                ('epargne_salariale',    'Épargne salariale (PEE, PERCO)',        'actions',     'asset',     150),
                ('lmnp',                 'LMNP',                                  'immobilier',  'asset',     160),
                ('location_nue',         'Location nue',                          'immobilier',  'asset',     170),
                ('scpi',                 'SCPI',                                  'immobilier',  'asset',     180),
                ('sci',                  'SCI',                                   'immobilier',  'asset',     190),
                ('siic',                 'SIIC (foncières cotées)',               'immobilier',  'asset',     200),
                ('credit_immo_locatif',  'Crédit immobilier locatif',             'immobilier',  'liability', 210),
                ('crowdfunding',         'Crowdfunding',                          'exotique',    'asset',     220),
                ('crypto',               'Cryptomonnaies',                        'exotique',    'asset',     230),
                ('gfi',                  'GFI / GFV',                             'exotique',    'asset',     240),
                ('or',                   'Or et métaux précieux',                 'exotique',    'asset',     250),
                ('autre_actif',          'Autre actif',                           'exotique',    'asset',     260),
                ('credit_conso',         'Crédit à la consommation',              'precaution',  'liability', 270),
                ('autre_passif',         'Autre passif',                          'precaution',  'liability', 280)
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE accounts (
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
        $this->addSql('CREATE INDEX accounts_level ON accounts(level_code)');

        $this->addSql(<<<'SQL'
            CREATE TABLE statements (
                account_id   INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
                month        TEXT NOT NULL CHECK (month GLOB '[0-9][0-9][0-9][0-9]-[0-1][0-9]'),
                amount_cents INTEGER NOT NULL,
                updated_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                PRIMARY KEY (account_id, month)
            ) WITHOUT ROWID
            SQL);
        $this->addSql('CREATE INDEX statements_month ON statements(month)');

        $this->addSql('CREATE TABLE settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)');

        $this->addSql(<<<'SQL'
            CREATE TABLE login_throttle (
                key          TEXT PRIMARY KEY,
                failures     INTEGER NOT NULL DEFAULT 0,
                last_failure INTEGER NOT NULL DEFAULT 0,
                locked_until INTEGER NOT NULL DEFAULT 0
            )
            SQL);
    }

    /**
     * Annuler le schéma initial supprimerait toutes les données : refusé volontairement.
     * Les migrations suivantes, elles, sont toutes réversibles.
     */
    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Le schéma initial ne peut pas être annulé : cela supprimerait toutes les données.');
    }
}
