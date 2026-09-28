<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

enum InvoiceType: string
{
    case DEPOSIT = 'deposit';
    case FINAL = 'final';
    case FULL = 'full';

    public function label(): string
    {
        return match ($this) {
            self::DEPOSIT => 'Zálohová faktura',
            self::FINAL => 'Konečná faktura (s odpočtem zálohy)',
            self::FULL => 'Faktura',
        };
    }

    /** Krátký název do nabídky zpráv hostovi — ať je hned vidět, co host platí. */
    public function messageLabel(): string
    {
        return match ($this) {
            self::DEPOSIT => 'Faktura na zálohu',
            self::FINAL => 'Faktura na doplatek',
            self::FULL => 'Faktura',
        };
    }
}
