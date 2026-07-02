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
 * Druh ručního pohybu v ledgeru cashflow. Příjmy z pobytů se sem neukládají
 * (drží je ReservationIncome), tady jsou jen výdaje, převody mezi vlastními
 * účty a korekce z uzávěrky.
 */
enum LedgerEntryType: string
{
    case EXPENSE = 'expense';
    case TRANSFER = 'transfer';
    case ADJUSTMENT = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::EXPENSE => 'Výdaj',
            self::TRANSFER => 'Převod',
            self::ADJUSTMENT => 'Korekce',
        };
    }
}
