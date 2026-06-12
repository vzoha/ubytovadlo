<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260508152956 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE email_log (id INT AUTO_INCREMENT NOT NULL, message_id VARCHAR(255) NOT NULL, from_address VARCHAR(255) DEFAULT NULL, subject VARCHAR(512) DEFAULT NULL, received_at DATETIME NOT NULL, parsed_at DATETIME DEFAULT NULL, status VARCHAR(16) NOT NULL, error LONGTEXT DEFAULT NULL, reservation_id INT DEFAULT NULL, INDEX IDX_6FB4883B83297E7 (reservation_id), INDEX idx_status (status), UNIQUE INDEX uniq_message_id (message_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation (id INT AUTO_INCREMENT NOT NULL, channel VARCHAR(16) NOT NULL, status VARCHAR(32) NOT NULL, external_id VARCHAR(64) DEFAULT NULL, check_in DATE NOT NULL, check_out DATE DEFAULT NULL, check_in_time TIME DEFAULT NULL, check_out_time TIME DEFAULT NULL, guests_adult SMALLINT DEFAULT 0 NOT NULL, guests_child SMALLINT DEFAULT 0 NOT NULL, guests_infant SMALLINT DEFAULT 0 NOT NULL, price_total NUMERIC(10, 2) DEFAULT NULL, price_currency VARCHAR(3) DEFAULT \'CZK\' NOT NULL, commission_amount NUMERIC(10, 2) DEFAULT NULL, commission_currency VARCHAR(3) DEFAULT NULL, net_payout NUMERIC(10, 2) DEFAULT NULL, guest_name VARCHAR(255) DEFAULT NULL, guest_email VARCHAR(255) DEFAULT NULL, guest_phone VARCHAR(64) DEFAULT NULL, guest_address LONGTEXT DEFAULT NULL, guest_company_name VARCHAR(255) DEFAULT NULL, guest_ico VARCHAR(16) DEFAULT NULL, guest_dic VARCHAR(32) DEFAULT NULL, guest_region VARCHAR(128) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_status (status), INDEX idx_check_in (check_in), UNIQUE INDEX uniq_channel_external_id (channel, external_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE email_log ADD CONSTRAINT FK_6FB4883B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE email_log DROP FOREIGN KEY FK_6FB4883B83297E7');
        $this->addSql('DROP TABLE email_log');
        $this->addSql('DROP TABLE reservation');
    }
}
