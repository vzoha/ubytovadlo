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
final class Version20260512161655 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE invoice (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(16) NOT NULL, series_year SMALLINT NOT NULL, type VARCHAR(16) NOT NULL, customer_name VARCHAR(255) NOT NULL, customer_street VARCHAR(255) DEFAULT NULL, customer_city VARCHAR(128) DEFAULT NULL, customer_zip VARCHAR(16) DEFAULT NULL, customer_country VARCHAR(64) DEFAULT NULL, customer_company_name VARCHAR(255) DEFAULT NULL, customer_ico VARCHAR(16) DEFAULT NULL, customer_dic VARCHAR(32) DEFAULT NULL, currency VARCHAR(3) NOT NULL, total_amount NUMERIC(12, 2) NOT NULL, original_amount NUMERIC(12, 2) DEFAULT NULL, original_currency VARCHAR(3) DEFAULT NULL, exchange_rate NUMERIC(14, 8) DEFAULT NULL, exchange_rate_date DATE DEFAULT NULL, issued_at DATE NOT NULL, due_at DATE NOT NULL, paid_at DATE DEFAULT NULL, payment_method VARCHAR(32) NOT NULL, bank_account VARCHAR(32) DEFAULT NULL, variable_symbol VARCHAR(32) DEFAULT NULL, qr_payload LONGTEXT DEFAULT NULL, pdf_path VARCHAR(512) DEFAULT NULL, notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, reservation_id INT NOT NULL, parent_invoice_id INT DEFAULT NULL, INDEX IDX_906517446ECA75AD (parent_invoice_id), INDEX idx_invoice_year (series_year), INDEX idx_invoice_reservation (reservation_id), UNIQUE INDEX uniq_invoice_number (number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE invoice_line (id INT AUTO_INCREMENT NOT NULL, position SMALLINT NOT NULL, description VARCHAR(255) NOT NULL, quantity NUMERIC(10, 2) NOT NULL, unit VARCHAR(16) DEFAULT NULL, unit_price NUMERIC(12, 2) NOT NULL, total_price NUMERIC(12, 2) NOT NULL, invoice_id INT NOT NULL, INDEX IDX_D3D1D6932989F1FD (invoice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_90651744B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_906517446ECA75AD FOREIGN KEY (parent_invoice_id) REFERENCES invoice (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE invoice_line ADD CONSTRAINT FK_D3D1D6932989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation ADD billing_mode VARCHAR(32) DEFAULT NULL, ADD motopress_payment_gateway VARCHAR(32) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_90651744B83297E7');
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_906517446ECA75AD');
        $this->addSql('ALTER TABLE invoice_line DROP FOREIGN KEY FK_D3D1D6932989F1FD');
        $this->addSql('DROP TABLE invoice');
        $this->addSql('DROP TABLE invoice_line');
        $this->addSql('ALTER TABLE reservation DROP billing_mode, DROP motopress_payment_gateway');
    }
}
