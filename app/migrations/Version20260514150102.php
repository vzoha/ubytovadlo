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
final class Version20260514150102 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Úklid (cost vs payout), generický Setting key-value store';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE cleaning (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(32) NOT NULL, cost_czk INT NOT NULL, payout_czk INT NOT NULL, paid_at DATE DEFAULT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, reservation_id INT NOT NULL, UNIQUE INDEX uniq_reservation (reservation_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE setting (`key` VARCHAR(64) NOT NULL, value LONGTEXT NOT NULL, note LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (`key`)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE cleaning ADD CONSTRAINT FK_3F6C5CF9B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE cleaning DROP FOREIGN KEY FK_3F6C5CF9B83297E7');
        $this->addSql('DROP TABLE cleaning');
        $this->addSql('DROP TABLE setting');
    }
}
