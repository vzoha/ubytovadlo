<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Config;

use App\Repository\SettingRepository;

/**
 * Zda evidovat i české hosty. Ubytovatel, který vybírá poplatek z pobytu, vede
 * evidenční knihu pro všechny hosty (§ 3g zák. o místních poplatcích) — proto je
 * ve výchozím stavu zapnuto. Kdo poplatek nevybírá, si evidenci Čechů vypne a do
 * check-inu vyplňují doklad jen cizinci (Ubyport). Ubyport hlášení cizinecké
 * policii se přepínač netýká — to je vždy jen o cizincích.
 */
final class GuestRegistrationSettings
{
    public const KEY_REGISTER_CZECH = 'guestbook.register_czech';

    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    public function registerCzechGuests(): bool
    {
        return $this->settings->getString(self::KEY_REGISTER_CZECH) !== '0';
    }

    /**
     * @return array{registerCzechGuests: bool}
     */
    public function currentValues(): array
    {
        return [
            'registerCzechGuests' => $this->registerCzechGuests(),
        ];
    }
}
