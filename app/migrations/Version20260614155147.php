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
 * Audit odeslaných zpráv hostům (guest_message).
 */
final class Version20260614155147 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabulka guest_message — audit odeslaných e-mailů hostům.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE guest_message (id INT AUTO_INCREMENT NOT NULL, kind VARCHAR(32) NOT NULL, to_email VARCHAR(255) NOT NULL, subject VARCHAR(255) NOT NULL, status VARCHAR(16) NOT NULL, error LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, reservation_id INT NOT NULL, INDEX IDX_F678F433B83297E7 (reservation_id), INDEX idx_guest_message_reservation_kind (reservation_id, kind), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE guest_message ADD CONSTRAINT FK_F678F433B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE guest_message DROP FOREIGN KEY FK_F678F433B83297E7');
        $this->addSql('DROP TABLE guest_message');
    }
}
