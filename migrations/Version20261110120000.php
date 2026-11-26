<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Limitation des tentatives sur symfony/rate-limiter : ses compteurs et les codes TOTP déjà utilisés vont
 * dans le pool de cache cache.security, sur la base (DoctrineDbalAdapter, table cache_items). L'ancienne
 * table login_throttle disparaît ; ses compteurs, temporaires, ne sont pas repris.
 */
final class Version20261110120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cache de sécurité sur la base (limiteurs, rejeu TOTP) ; suppression de login_throttle';
    }

    public function up(Schema $schema): void
    {
        // Schéma attendu par Symfony\Component\Cache\Adapter\DoctrineDbalAdapter.
        $this->addSql(<<<'SQL'
            CREATE TABLE cache_items (
                item_id       VARCHAR(255) NOT NULL PRIMARY KEY,
                item_data     BLOB NOT NULL,
                item_lifetime INTEGER DEFAULT NULL,
                item_time     INTEGER NOT NULL
            )
            SQL);
        $this->addSql('DROP TABLE login_throttle');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE login_throttle (
                key          TEXT PRIMARY KEY,
                failures     INTEGER NOT NULL DEFAULT 0,
                last_failure INTEGER NOT NULL DEFAULT 0,
                locked_until INTEGER NOT NULL DEFAULT 0
            )
            SQL);
        $this->addSql('DROP TABLE cache_items');
    }
}
