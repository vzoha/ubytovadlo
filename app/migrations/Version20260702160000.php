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
 * Fronta notifikací ubytovateli (pending_owner_notification). Trigger založí
 * záznam, cron rozešle podle režimu doručení (okamžitě / denní souhrn).
 */
final class Version20260702160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fronta e-mailových notifikací ubytovateli (pending_owner_notification)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
                CREATE TABLE pending_owner_notification (
                  id INT AUTO_INCREMENT NOT NULL,
                  type VARCHAR(32) NOT NULL,
                  delivery_mode VARCHAR(16) NOT NULL,
                  reservation_id INT DEFAULT NULL,
                  payload JSON DEFAULT NULL,
                  created_at DATETIME NOT NULL,
                  sent_at DATETIME DEFAULT NULL,
                  INDEX idx_owner_notif_reservation (reservation_id),
                  INDEX idx_owner_notif_pending (delivery_mode, sent_at),
                  PRIMARY KEY (id)
                ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE pending_owner_notification ADD CONSTRAINT FK_owner_notif_reservation FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE pending_owner_notification DROP FOREIGN KEY FK_owner_notif_reservation');
        $this->addSql('DROP TABLE pending_owner_notification');
    }
}
