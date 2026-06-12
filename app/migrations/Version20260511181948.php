<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260511181948 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE airbnb_statement (id INT AUTO_INCREMENT NOT NULL, period_from DATE NOT NULL, period_to DATE NOT NULL, commission_czk NUMERIC(12, 2) NOT NULL, pdf_path VARCHAR(512) NOT NULL, notes LONGTEXT DEFAULT NULL, uploaded_at DATETIME NOT NULL, INDEX idx_period_to (period_to), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE airbnb_statement');
    }
}
