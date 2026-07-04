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
 * Pořadové číslo faktury do vlastního sloupce (invoice.series_sequence), aby šlo
 * číslo faktury zobrazit v libovolném formátu (předpona/oddělovače) nezávisle na
 * alokaci. Backfill z dosavadních čísel tvaru RRRR### (pořadí = číslice za rokem).
 * Zároveň rozšíření sloupce number na 32 znaků kvůli delším formátům.
 */
final class Version20260704120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Pořadové číslo faktury do sloupce invoice.series_sequence + rozšíření number(32)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice CHANGE number number VARCHAR(32) NOT NULL');
        $this->addSql('ALTER TABLE invoice ADD series_sequence SMALLINT DEFAULT NULL');
        // Backfill z dosavadního tvaru RRRR### — pořadí jsou číslice za čtyřmístným rokem.
        $this->addSql('UPDATE invoice SET series_sequence = CAST(SUBSTRING(number, 5) AS UNSIGNED) WHERE series_sequence IS NULL');
        // Pojistka pro případná nestandardní čísla, ať sloupec může být NOT NULL.
        $this->addSql('UPDATE invoice SET series_sequence = 0 WHERE series_sequence IS NULL');
        $this->addSql('ALTER TABLE invoice CHANGE series_sequence series_sequence SMALLINT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP series_sequence');
        $this->addSql('ALTER TABLE invoice CHANGE number number VARCHAR(16) NOT NULL');
    }
}
