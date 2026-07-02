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
 * (drží je ReservationReceipt); tady jsou výdaje, nerezervační příjmy (úroky,
 * storno-poplatky, náhrady), převody mezi vlastními účty a korekce z uzávěrky.
 */
enum LedgerEntryType: string
{
    case EXPENSE = 'expense';
    case INCOME = 'income';
    case TRANSFER = 'transfer';
    case ADJUSTMENT = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::EXPENSE => 'Výdaj',
            self::INCOME => 'Ostatní příjem',
            self::TRANSFER => 'Převod',
            self::ADJUSTMENT => 'Korekce',
        };
    }
}
