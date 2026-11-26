<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Language of the emails chosen in the profile: "fr" or "en"; NULL, the language of the interface at the time of
 * sending (behaviour until now). A preference, not wealth data: stored in clear, like the language of the reminder.
 */
final class Version20261118140000 extends AbstractMigration
{
    #[\Override]
    public function getDescription(): string
    {
        return 'Langue des e-mails choisie dans le profil';
    }

    #[\Override]
    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE users ADD COLUMN email_locale TEXT CHECK (email_locale IS NULL OR email_locale IN ('fr', 'en'))");
    }

    #[\Override]
    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN email_locale');
    }
}
