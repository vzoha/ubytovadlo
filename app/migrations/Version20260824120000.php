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
 * Hlídané termíny — opakované úkony (revize, servis, administrativa, platby,
 * sezónní práce) a historie jejich provedení s protokolem a vazbou na výdaj.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabulky recurring_task a task_completion pro hlídání termínů.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE recurring_task (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, category VARCHAR(16) NOT NULL, recurrence VARCHAR(16) NOT NULL, interval_value INT DEFAULT NULL, interval_unit VARCHAR(8) DEFAULT NULL, due_on DATE DEFAULT NULL, last_done_on DATE DEFAULT NULL, lead_days INT NOT NULL, vendor VARCHAR(120) DEFAULT NULL, vendor_contact VARCHAR(160) DEFAULT NULL, legal_reference VARCHAR(160) DEFAULT NULL, estimated_cost_czk INT DEFAULT NULL, expense_category VARCHAR(24) DEFAULT NULL, active TINYINT NOT NULL, note LONGTEXT DEFAULT NULL, catalog_key VARCHAR(48) DEFAULT NULL, reminded_for_due DATE DEFAULT NULL, last_reminded_on DATE DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX idx_task_due (active, due_on), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE task_completion (id INT AUTO_INCREMENT NOT NULL, task_id INT NOT NULL, ledger_entry_id INT DEFAULT NULL, done_on DATE NOT NULL, valid_until DATE DEFAULT NULL, vendor VARCHAR(120) DEFAULT NULL, cost_czk INT DEFAULT NULL, note LONGTEXT DEFAULT NULL, attachment_path VARCHAR(255) DEFAULT NULL, attachment_name VARCHAR(160) DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_24C57CD18DB60186 (task_id), INDEX IDX_24C57CD1EB264CB8 (ledger_entry_id), INDEX idx_completion_task (task_id, done_on), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE task_completion ADD CONSTRAINT FK_24C57CD18DB60186 FOREIGN KEY (task_id) REFERENCES recurring_task (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE task_completion ADD CONSTRAINT FK_24C57CD1EB264CB8 FOREIGN KEY (ledger_entry_id) REFERENCES ledger_entry (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE task_completion DROP FOREIGN KEY FK_24C57CD18DB60186');
        $this->addSql('ALTER TABLE task_completion DROP FOREIGN KEY FK_24C57CD1EB264CB8');
        $this->addSql('DROP TABLE task_completion');
        $this->addSql('DROP TABLE recurring_task');
    }
}
