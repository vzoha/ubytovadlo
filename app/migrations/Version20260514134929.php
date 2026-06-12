<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260514134929 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Elektřina: tariff, reading + spotřeba VT/NT na rezervaci (evidenční, v ceně pobytu)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE electricity_reading (id INT AUTO_INCREMENT NOT NULL, read_at DATE NOT NULL, vt_meter INT NOT NULL, nt_meter INT NOT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_read_at (read_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE electricity_tariff (id INT AUTO_INCREMENT NOT NULL, valid_from DATE NOT NULL, vt_rate NUMERIC(8, 4) NOT NULL, nt_rate NUMERIC(8, 4) NOT NULL, note LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_valid_from (valid_from), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reservation ADD vt_kwh INT DEFAULT NULL, ADD nt_kwh INT DEFAULT NULL, ADD electricity_source VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE electricity_reading');
        $this->addSql('DROP TABLE electricity_tariff');
        $this->addSql('ALTER TABLE reservation DROP vt_kwh, DROP nt_kwh, DROP electricity_source');
    }
}
