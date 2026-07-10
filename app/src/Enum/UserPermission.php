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
 * Doplňkové právo přiřaditelné uživateli navrch k jeho {@see UserRole}.
 * Správce a Admin je mají vždy (dědičnost v role_hierarchy); typicky se
 * přiřazuje uklízečce, aby zvládla i konkrétní agendu.
 */
enum UserPermission: string
{
    case ELECTRICITY = 'ROLE_ELECTRICITY';

    public function label(): string
    {
        return match ($this) {
            self::ELECTRICITY => 'Odečty elektřiny',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ELECTRICITY => 'Zápis stavů elektroměru (VT/NT) před a po pobytu.',
        };
    }
}
