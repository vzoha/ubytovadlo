<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Service\Cleaning;

use App\Enum\CleaningType;
use App\Repository\SettingRepository;

/**
 * Zobrazovaný název typu úklidu. Instance si může přepsat obecný název konkrétním
 * (jméno osoby/firmy) přes nastavení `cleaning.<value>.label` — jména tak žijí
 * v datech, ne ve veřejném kódu. Bez override se použije neutrální label z enumu.
 */
final class CleaningTypeLabeler
{
    public function __construct(private readonly SettingRepository $settings)
    {
    }

    public function label(CleaningType $type): string
    {
        $override = $this->settings->getString('cleaning.' . $type->value . '.label');

        return $override !== null && $override !== '' ? $override : $type->label();
    }
}
