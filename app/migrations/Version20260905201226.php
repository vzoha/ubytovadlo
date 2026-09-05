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
 * Zařízení může v hlášení na Ubyport vystupovat pod vlastní adresou — vedle
 * adresy objektu přibývá sada sloupců reporting_*. Prázdné „nevyplněno"
 * má nově jednu podobu (NULL), proto se u adresních sloupců povoluje NULL.
 */
final class Version20260905201226 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vlastní adresa zařízení pro hlášení na Ubyport';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE accommodation_profile
            ADD reporting_okres VARCHAR(128) DEFAULT NULL,
            ADD reporting_obec VARCHAR(128) DEFAULT NULL,
            ADD reporting_cast_obce VARCHAR(128) DEFAULT NULL,
            ADD reporting_ulice VARCHAR(128) DEFAULT NULL,
            ADD reporting_cp VARCHAR(16) DEFAULT NULL,
            ADD reporting_co VARCHAR(16) DEFAULT NULL,
            ADD reporting_psc VARCHAR(8) DEFAULT NULL,
            CHANGE okres okres VARCHAR(128) DEFAULT NULL,
            CHANGE obec obec VARCHAR(128) DEFAULT NULL,
            CHANGE psc psc VARCHAR(8) DEFAULT NULL');
        $this->addSql("UPDATE accommodation_profile SET okres = NULL WHERE okres = ''");
        $this->addSql("UPDATE accommodation_profile SET obec = NULL WHERE obec = ''");
        $this->addSql("UPDATE accommodation_profile SET psc = NULL WHERE psc = ''");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE accommodation_profile SET okres = '' WHERE okres IS NULL");
        $this->addSql("UPDATE accommodation_profile SET obec = '' WHERE obec IS NULL");
        $this->addSql("UPDATE accommodation_profile SET psc = '' WHERE psc IS NULL");
        $this->addSql('ALTER TABLE accommodation_profile
            DROP reporting_okres,
            DROP reporting_obec,
            DROP reporting_cast_obce,
            DROP reporting_ulice,
            DROP reporting_cp,
            DROP reporting_co,
            DROP reporting_psc,
            CHANGE okres okres VARCHAR(128) NOT NULL,
            CHANGE obec obec VARCHAR(128) NOT NULL,
            CHANGE psc psc VARCHAR(8) NOT NULL');
    }
}
