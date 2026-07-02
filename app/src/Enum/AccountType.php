<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Typ účtu v evidenci cashflow. Univerzální — konkrétní účty si zakládá
 * uživatel sám, typ jen rozlišuje bankovní zůstatek od hotovosti (kam se
 * automaticky zařadí příjmy hrazené „hotově").
 */
enum AccountType: string
{
    case BANK = 'bank';
    case CASH = 'cash';

    public function label(): string
    {
        return match ($this) {
            self::BANK => 'Bankovní účet',
            self::CASH => 'Hotovost',
        };
    }
}
