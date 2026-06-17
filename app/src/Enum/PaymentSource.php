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
 * Odkud přišel záznam o platbě. Zatím jen e-mailová notifikace České spořitelny
 * ("Přišla platba"); konektory dalších bank / import výpisu přibudou jako další case.
 */
enum PaymentSource: string
{
    case CS_EMAIL = 'cs_email';

    public function label(): string
    {
        return match ($this) {
            self::CS_EMAIL => 'Notifikace ČS',
        };
    }
}
