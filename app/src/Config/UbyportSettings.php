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
 * Zda instance hlásí ubytované cizince cizinecké policii. Povinnost má každý,
 * kdo cizince ubytuje, takže výchozí stav je zapnuto; kdo cizince nikdy nemá,
 * si modul vypne a nevidí jeho frontu ani nastavení. Evidenční knihy hostů se
 * přepínač netýká — tu ukládá jiný zákon.
 */
final class UbyportSettings
{
    public const KEY_ENABLED = 'ubyport.enabled';

    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->settings->getString(self::KEY_ENABLED) !== '0';
    }

    /**
     * @return array{ubyportEnabled: bool}
     */
    public function currentValues(): array
    {
        return ['ubyportEnabled' => $this->isEnabled()];
    }
}
