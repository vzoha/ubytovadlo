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
 * Datová migrace: přejmenování typů úklidu z instance-specifických (jména osob)
 * na obecné sémantické hodnoty (OSS). Konkrétní jména ("Barča", "Nikča") se nově
 * drží jako konfigurovatelné labely v nastavení (Setting `cleaning.<value>.label`),
 * mimo veřejný kód.
 *
 *   barca           → owner
 *   nikca           → cleaner
 *   nikca_s_pranim  → cleaner_laundry
 *   externi         → external
 *
 * Přejmenují se i klíče ceníku v tabulce setting (cleaning.barca.* → cleaning.owner.*, …).
 * Schéma se nemění (cleaning.type zůstává VARCHAR) — jde čistě o obsah.
 */
final class Version20260612140000 extends AbstractMigration
{
    /** @var array<string, string> staré → nové */
    private const TYPE_MAP = [
        'barca' => 'owner',
        'nikca' => 'cleaner',
        'nikca_s_pranim' => 'cleaner_laundry',
        'externi' => 'external',
    ];

    /** @var array<string, string> staré → nové klíče nastavení */
    private const SETTING_KEY_MAP = [
        'cleaning.barca.threshold_guests' => 'cleaning.owner.threshold_guests',
        'cleaning.barca.price_small' => 'cleaning.owner.price_small',
        'cleaning.barca.price_large' => 'cleaning.owner.price_large',
        'cleaning.nikca.price' => 'cleaning.cleaner.price',
        'cleaning.nikca_s_pranim.price' => 'cleaning.cleaner_laundry.price',
        'cleaning.externi.price' => 'cleaning.external.price',
    ];

    public function getDescription(): string
    {
        return 'Přejmenování typů úklidu na obecné sémantické hodnoty (barca→owner, …) + klíčů ceníku';
    }

    public function up(Schema $schema): void
    {
        $this->remapTypes(self::TYPE_MAP);
        $this->remapSettingKeys(self::SETTING_KEY_MAP);
    }

    public function down(Schema $schema): void
    {
        $this->remapTypes(array_flip(self::TYPE_MAP));
        $this->remapSettingKeys(array_flip(self::SETTING_KEY_MAP));
    }

    /**
     * @param array<string, string> $map
     */
    private function remapTypes(array $map): void
    {
        foreach ($map as $from => $to) {
            $this->addSql('UPDATE cleaning SET type = :to WHERE type = :from', ['to' => $to, 'from' => $from]);
        }
    }

    /**
     * @param array<string, string> $map
     */
    private function remapSettingKeys(array $map): void
    {
        foreach ($map as $from => $to) {
            $this->addSql('UPDATE setting SET `key` = :to WHERE `key` = :from', ['to' => $to, 'from' => $from]);
        }
    }
}
