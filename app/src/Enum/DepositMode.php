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
 * Jak se počítá záloha u toků, které ji vyžadují (web klasika, ruční rezervace).
 * Žádná záloha znamená, že se místo zálohy + doplatku vystaví jedna faktura na celou.
 */
enum DepositMode: string
{
    case FIXED = 'fixed';
    case PERCENT = 'percent';
    case NONE = 'none';

    public function label(): string
    {
        return match ($this) {
            self::FIXED => 'Fixní částka',
            self::PERCENT => 'Procento z ceny',
            self::NONE => 'Bez zálohy',
        };
    }
}
