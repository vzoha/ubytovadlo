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
 * Časová osa rezervace: ruční CRM poznámky (reservation_note) + naplánované akce
 * (reservation_action). Systémové události se neukládají, odvozuje je TimelineBuilder.
 */
final class Version20260614070647 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Časová osa rezervace — tabulky reservation_note a reservation_action';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE reservation_action (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(32) NOT NULL, scheduled_for DATETIME NOT NULL, status VARCHAR(16) NOT NULL, origin VARCHAR(8) NOT NULL, payload JSON DEFAULT NULL, executed_at DATETIME DEFAULT NULL, result LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, reservation_id INT NOT NULL, INDEX idx_action_reservation (reservation_id), INDEX idx_action_due (status, scheduled_for), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE reservation_note (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(16) NOT NULL, body LONGTEXT NOT NULL, occurred_at DATETIME NOT NULL, created_at DATETIME NOT NULL, reservation_id INT NOT NULL, author_id INT DEFAULT NULL, INDEX IDX_D984377CF675F31B (author_id), INDEX idx_note_reservation (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE reservation_action ADD CONSTRAINT FK_F3DC509B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_note ADD CONSTRAINT FK_D984377CB83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE reservation_note ADD CONSTRAINT FK_D984377CF675F31B FOREIGN KEY (author_id) REFERENCES app_user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_action DROP FOREIGN KEY FK_F3DC509B83297E7');
        $this->addSql('ALTER TABLE reservation_note DROP FOREIGN KEY FK_D984377CB83297E7');
        $this->addSql('ALTER TABLE reservation_note DROP FOREIGN KEY FK_D984377CF675F31B');
        $this->addSql('DROP TABLE reservation_action');
        $this->addSql('DROP TABLE reservation_note');
    }
}
