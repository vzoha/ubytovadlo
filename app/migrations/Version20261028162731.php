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

final class Version20261028162731 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Průběh online check-inu (checkin_event).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE checkin_event (id INT AUTO_INCREMENT NOT NULL, step VARCHAR(32) NOT NULL, details JSON NOT NULL, occurred_at DATETIME NOT NULL, reservation_id INT NOT NULL, INDEX IDX_430FBB24B83297E7 (reservation_id), INDEX idx_checkin_event_reservation (reservation_id, occurred_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE checkin_event ADD CONSTRAINT FK_430FBB24B83297E7 FOREIGN KEY (reservation_id) REFERENCES reservation (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE checkin_event DROP FOREIGN KEY FK_430FBB24B83297E7');
        $this->addSql('DROP TABLE checkin_event');
    }
}
