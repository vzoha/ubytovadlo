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
 * Nahrazuje agregát reservation_income (1 řádek/rezervace) dílčími platbami
 * reservation_receipt (víc řádků/rezervace, každá s vlastním datem přijetí).
 * Ruční výplaty (manually_overridden) přenese jako receipt s origin=manual;
 * automatické receipty (faktury/platby/odhad) dorovná `app:cashflow:recompute-incomes`.
 */
final class Version20260702102655 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Dílčí platby rezervace (reservation_receipt) místo agregátu reservation_income';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE reservation_receipt (
                  id INT AUTO_INCREMENT NOT NULL,
                  amount_czk NUMERIC(12, 2) NOT NULL,
                  received_on DATE DEFAULT NULL,
                  source VARCHAR(16) NOT NULL,
                  origin_type VARCHAR(16) NOT NULL,
                  origin_id INT NOT NULL,
                  manually_overridden TINYINT NOT NULL,
                  updated_at DATETIME NOT NULL,
                  reservation_id INT NOT NULL,
                  account_id INT DEFAULT NULL,
                  INDEX IDX_340C0DC0B83297E7 (reservation_id),
                  INDEX IDX_340C0DC09B6B5FBA (account_id),
                  INDEX idx_receipt_received_on (received_on),
                  UNIQUE INDEX uniq_receipt_origin (reservation_id, origin_type, origin_id),
                  PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE reservation_receipt ADD CONSTRAINT FK_340C0DC0B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_receipt ADD CONSTRAINT FK_340C0DC09B6B5FBA FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL');

        // Ruční výplaty přenést (auto se dorovná recompute po nasazení).
        $this->addSql(<<<'SQL'
                INSERT INTO reservation_receipt
                  (reservation_id, account_id, amount_czk, received_on, source, origin_type, origin_id, manually_overridden, updated_at)
                SELECT reservation_id, account_id, amount_czk, received_on, source, 'manual', 0, 1, updated_at
                FROM reservation_income
                WHERE manually_overridden = 1
            SQL);

        $this->addSql('DROP TABLE reservation_income');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE reservation_income (
                  id INT AUTO_INCREMENT NOT NULL,
                  amount_czk NUMERIC(12, 2) NOT NULL,
                  received_on DATE DEFAULT NULL,
                  source VARCHAR(16) NOT NULL,
                  manually_overridden TINYINT DEFAULT 0 NOT NULL,
                  updated_at DATETIME NOT NULL,
                  reservation_id INT NOT NULL,
                  account_id INT DEFAULT NULL,
                  UNIQUE INDEX uniq_income_reservation (reservation_id),
                  INDEX IDX_income_account (account_id),
                  PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE reservation_income ADD CONSTRAINT FK_income_account FOREIGN KEY (account_id) REFERENCES account (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE reservation_income ADD CONSTRAINT FK_income_reservation FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');

        // Ruční výplaty vrátit (symetrie k up()); auto se dorovná recompute.
        $this->addSql(<<<'SQL'
                INSERT INTO reservation_income
                  (reservation_id, account_id, amount_czk, received_on, source, manually_overridden, updated_at)
                SELECT reservation_id, account_id, amount_czk, received_on, source, 1, updated_at
                FROM reservation_receipt
                WHERE manually_overridden = 1
            SQL);

        $this->addSql('ALTER TABLE reservation_receipt DROP FOREIGN KEY FK_340C0DC0B83297E7');
        $this->addSql('ALTER TABLE reservation_receipt DROP FOREIGN KEY FK_340C0DC09B6B5FBA');
        $this->addSql('DROP TABLE reservation_receipt');
    }
}
