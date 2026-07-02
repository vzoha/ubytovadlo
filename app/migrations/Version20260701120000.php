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
 * Modul Cashflow: účty, ledger (výdaje/převody/korekce), uzávěrky účtů a
 * reálně přijatý příjem per rezervace. Zakládá výchozí účty (bankovní, hotovost).
 */
final class Version20260701120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cashflow: account, ledger_entry, balance_statement, reservation_income.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE account (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(64) NOT NULL, type VARCHAR(8) NOT NULL, opening_balance_czk INT DEFAULT 0 NOT NULL, opening_date DATE NOT NULL, sort_order INT DEFAULT 0 NOT NULL, active TINYINT(1) DEFAULT 1 NOT NULL, note LONGTEXT DEFAULT NULL, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE ledger_entry (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(16) NOT NULL, occurred_on DATE NOT NULL, amount_czk INT NOT NULL, category VARCHAR(24) DEFAULT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, account_id INT NOT NULL, counter_account_id INT DEFAULT NULL, reservation_id INT DEFAULT NULL, INDEX idx_ledger_account_date (account_id, occurred_on), INDEX IDX_ledger_counter (counter_account_id), INDEX IDX_ledger_reservation (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE balance_statement (id INT AUTO_INCREMENT NOT NULL, statement_date DATE NOT NULL, actual_balance_czk INT NOT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, account_id INT NOT NULL, INDEX idx_statement_account_date (account_id, statement_date), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('CREATE TABLE reservation_income (id INT AUTO_INCREMENT NOT NULL, amount_czk NUMERIC(12, 2) NOT NULL, received_on DATE DEFAULT NULL, source VARCHAR(16) NOT NULL, manually_overridden TINYINT(1) DEFAULT 0 NOT NULL, updated_at DATETIME NOT NULL, reservation_id INT NOT NULL, account_id INT DEFAULT NULL, UNIQUE INDEX uniq_income_reservation (reservation_id), INDEX IDX_income_account (account_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $this->addSql('ALTER TABLE ledger_entry ADD CONSTRAINT FK_ledger_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ledger_entry ADD CONSTRAINT FK_ledger_counter FOREIGN KEY (counter_account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE ledger_entry ADD CONSTRAINT FK_ledger_reservation FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE balance_statement ADD CONSTRAINT FK_statement_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_income ADD CONSTRAINT FK_income_reservation FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_income ADD CONSTRAINT FK_income_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL');

        // Výchozí účty, ať příjmový upsert i zůstatky fungují ihned po nasazení.
        $this->addSql("INSERT INTO account (name, type, opening_balance_czk, opening_date, sort_order, active) VALUES ('Bankovní účet', 'bank', 0, '2026-01-01', 0, 1)");
        $this->addSql("INSERT INTO account (name, type, opening_balance_czk, opening_date, sort_order, active) VALUES ('Hotovost', 'cash', 0, '2026-01-01', 1, 1)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ledger_entry DROP FOREIGN KEY FK_ledger_account');
        $this->addSql('ALTER TABLE ledger_entry DROP FOREIGN KEY FK_ledger_counter');
        $this->addSql('ALTER TABLE ledger_entry DROP FOREIGN KEY FK_ledger_reservation');
        $this->addSql('ALTER TABLE balance_statement DROP FOREIGN KEY FK_statement_account');
        $this->addSql('ALTER TABLE reservation_income DROP FOREIGN KEY FK_income_reservation');
        $this->addSql('ALTER TABLE reservation_income DROP FOREIGN KEY FK_income_account');
        $this->addSql('DROP TABLE reservation_income');
        $this->addSql('DROP TABLE balance_statement');
        $this->addSql('DROP TABLE ledger_entry');
        $this->addSql('DROP TABLE account');
    }
}
