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
 * Tabulka connector — zapínatelné zdroje dat (web/MotoPress, Booking, Airbnb,
 * banka) se stavem zdraví (poslední běh, poslední aktivita, výsledek). Zapnutí
 * MotoPressu se přenáší z dosavadního nastavení motopress.enabled do řádku konektoru.
 */
final class Version20260704130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tabulka connector (zapínatelné zdroje dat + zdraví) + přenos motopress.enabled.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE connector (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(32) NOT NULL, enabled TINYINT(1) NOT NULL, last_run_at DATETIME DEFAULT NULL, last_activity_at DATETIME DEFAULT NULL, last_status VARCHAR(16) NOT NULL, last_error LONGTEXT DEFAULT NULL, UNIQUE INDEX uniq_connector_type (type), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        // Řádky založíme rovnou (ne líně) — konektory jsou pevná množina, tím odpadá
        // souběh při prvním dotyku. MotoPress zdědí dosavadní přepínač motopress.enabled
        // (výslovné '0' = vypnuto), ostatní jsou zapnuté.
        $motopressEnabled = $this->connection->fetchOne("SELECT `value` FROM setting WHERE `key` = 'motopress.enabled'") === '0' ? 0 : 1;
        $this->addSql("INSERT INTO connector (type, enabled, last_status) VALUES ('motopress', {$motopressEnabled}, 'idle')");
        $this->addSql("INSERT INTO connector (type, enabled, last_status) VALUES ('booking', 1, 'idle')");
        $this->addSql("INSERT INTO connector (type, enabled, last_status) VALUES ('airbnb', 1, 'idle')");
        $this->addSql("INSERT INTO connector (type, enabled, last_status) VALUES ('bank_cs', 1, 'idle')");
        $this->addSql("DELETE FROM setting WHERE `key` = 'motopress.enabled'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE connector');
    }
}
