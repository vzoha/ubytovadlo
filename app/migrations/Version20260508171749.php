<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508171749 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation ADD guest_street VARCHAR(255) DEFAULT NULL, ADD guest_city VARCHAR(128) DEFAULT NULL, ADD guest_zip VARCHAR(16) DEFAULT NULL, DROP guest_address');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE reservation ADD guest_address LONGTEXT DEFAULT NULL, DROP guest_street, DROP guest_city, DROP guest_zip');
    }
}
