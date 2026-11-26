<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * New account types:
 * - second home: property type (real estate level of the pyramid, not the main residence) and its loan type, chosen
 *   by the property form for the loans of such a property;
 * - tangible assets of the alternative (exotique) level: silver and other precious metals (gold keeps its type,
 *   whose label becomes "Or"), jewellery, watches, works of art and collectibles, wines and spirits, car, motorbike.
 */
final class Version20261115140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Types résidence secondaire, crédit de résidence secondaire et biens exotiques';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO account_types (code, label, default_level, nature, position) VALUES
                ('residence_secondaire', 'Résidence secondaire',                   'immobilier', 'asset',     155),
                ('credit_immo_rs',       'Crédit immobilier résidence secondaire', 'immobilier', 'liability', 205),
                ('argent_metal',         'Argent (métal)',                         'exotique',   'asset',     251),
                ('autres_metaux',        'Platine, palladium et autres métaux précieux', 'exotique', 'asset', 252),
                ('bijoux',               'Bijoux',                                 'exotique',   'asset',     253),
                ('montres',              'Montres',                                'exotique',   'asset',     254),
                ('art_collection',       'Œuvres d''art et objets de collection',  'exotique',   'asset',     255),
                ('vins',                 'Vins et spiritueux',                     'exotique',   'asset',     256),
                ('voiture',              'Voiture',                                'exotique',   'asset',     257),
                ('moto',                 'Moto',                                   'exotique',   'asset',     258)
            SQL);
        $this->addSql("UPDATE account_types SET label = 'Or' WHERE code = 'or'");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE account_types SET label = 'Or et métaux précieux' WHERE code = 'or'");
        // Refused by the foreign keys while an account still uses one of these types.
        $this->addSql(<<<'SQL'
            DELETE FROM account_types WHERE code IN ('residence_secondaire', 'credit_immo_rs', 'argent_metal',
                'autres_metaux', 'bijoux', 'montres', 'art_collection', 'vins', 'voiture', 'moto')
            SQL);
    }
}
