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
 * Sjednocení ceníku úklidu: dříve měl práh + dvě ceny jen „vlastní úklid" (owner),
 * ostatní typy paušál (cleaning.<type>.price). Nově má každý typ jednotně
 * threshold_guests + price_small + price_large. Paušál se zachová tak, že se stará
 * cena zkopíruje do obou (price_small = price_large = původní hodnota).
 *
 * Owner už nové schéma má (threshold_guests/price_small/price_large) — neměníme.
 * Schéma DB se nemění, jde čistě o klíče v tabulce setting.
 */
final class Version20260612150000 extends AbstractMigration
{
    /** Typy, které dříve měly paušál cleaning.<type>.price. */
    private const FLAT_TYPES = ['cleaner', 'cleaner_laundry', 'external'];

    public function getDescription(): string
    {
        return 'Sjednocení ceníku úklidu — paušál cleaning.<type>.price → price_small + price_large';
    }

    public function up(Schema $schema): void
    {
        foreach (self::FLAT_TYPES as $t) {
            // 1) odvoď price_large z původní paušální ceny (jen pokud existuje)
            $this->addSql(
                'INSERT IGNORE INTO setting (`key`, value, updated_at) '
                . "SELECT 'cleaning.{$t}.price_large', value, NOW() FROM setting WHERE `key` = 'cleaning.{$t}.price'"
            );
            // 2) přejmenuj původní paušál na price_small
            $this->addSql("UPDATE setting SET `key` = 'cleaning.{$t}.price_small' WHERE `key` = 'cleaning.{$t}.price'");
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::FLAT_TYPES as $t) {
            $this->addSql(
                'INSERT IGNORE INTO setting (`key`, value, updated_at) '
                . "SELECT 'cleaning.{$t}.price', value, NOW() FROM setting WHERE `key` = 'cleaning.{$t}.price_small'"
            );
            $this->addSql("DELETE FROM setting WHERE `key` IN ('cleaning.{$t}.price_small', 'cleaning.{$t}.price_large')");
        }
    }
}
