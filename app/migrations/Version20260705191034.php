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
 * iCal import obsazenosti: konektor dostává volitelnou konfiguraci (config JSON —
 * URL feedu) a rezervace nese UID iCal bloku (stabilní identita napříč běhy,
 * podklad pro adopci a detekci storn).
 */
final class Version20260705191034 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'connector.config (JSON) + reservation.ical_uid (+ index) pro iCal import obsazenosti.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE connector ADD config JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE reservation ADD ical_uid VARCHAR(128) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_ical_uid ON reservation (ical_uid)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_ical_uid ON reservation');
        $this->addSql('ALTER TABLE reservation DROP ical_uid');
        $this->addSql('ALTER TABLE connector DROP config');
    }
}
