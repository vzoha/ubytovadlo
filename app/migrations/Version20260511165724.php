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
final class Version20260511165724 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE booking_monthly_invoice (id INT AUTO_INCREMENT NOT NULL, invoice_number VARCHAR(32) NOT NULL, issued_at DATE NOT NULL, period_from DATE NOT NULL, period_to DATE NOT NULL, currency VARCHAR(3) NOT NULL, room_sales NUMERIC(12, 2) NOT NULL, commission NUMERIC(12, 2) NOT NULL, payment_fee NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, total_payable NUMERIC(12, 2) NOT NULL, booking_exchange_rate NUMERIC(14, 8) DEFAULT NULL, pdf_path VARCHAR(512) NOT NULL, created_at DATETIME NOT NULL, source_email_id INT DEFAULT NULL, INDEX IDX_21163108EB12F71B (source_email_id), INDEX idx_period_to (period_to), UNIQUE INDEX uniq_invoice_number (invoice_number), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE vat_period (id INT AUTO_INCREMENT NOT NULL, year SMALLINT NOT NULL, month SMALLINT NOT NULL, sum_base_czk NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, sum_vat_czk NUMERIC(12, 2) DEFAULT \'0.00\' NOT NULL, filed_at DATETIME DEFAULT NULL, notes LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX uniq_year_month (year, month), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE booking_monthly_invoice ADD CONSTRAINT FK_21163108EB12F71B FOREIGN KEY (source_email_id) REFERENCES email_log (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reservation ADD vat_duzp DATE DEFAULT NULL, ADD vat_cnb_rate NUMERIC(14, 8) DEFAULT NULL, ADD vat_cnb_rate_date DATE DEFAULT NULL, ADD vat_base_czk NUMERIC(12, 2) DEFAULT NULL, ADD vat_amount_czk NUMERIC(12, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE booking_monthly_invoice DROP FOREIGN KEY FK_21163108EB12F71B');
        $this->addSql('DROP TABLE booking_monthly_invoice');
        $this->addSql('DROP TABLE vat_period');
        $this->addSql('ALTER TABLE reservation DROP vat_duzp, DROP vat_cnb_rate, DROP vat_cnb_rate_date, DROP vat_base_czk, DROP vat_amount_czk');
    }
}
