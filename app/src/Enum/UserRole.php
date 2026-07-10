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
 * Základní role uživatele — určuje bundl oblastí, do kterých má přístup.
 * Doplňková, jednotlivě přiřaditelná práva řeší {@see UserPermission}.
 */
enum UserRole: string
{
    case ADMIN = 'ROLE_ADMIN';
    case MANAGER = 'ROLE_USER';
    case CLEANER = 'ROLE_CLEANER';

    /** Pořadí od nejsilnější — pro odvození primární role z pole rolí. */
    public const PRIORITY = [self::ADMIN, self::MANAGER, self::CLEANER];

    public function label(): string
    {
        return match ($this) {
            self::ADMIN => 'Admin',
            self::MANAGER => 'Správce',
            self::CLEANER => 'Uklízečka',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ADMIN => 'Vše včetně nastavení instance a správy uživatelů.',
            self::MANAGER => 'Provoz i finance — rezervace, hosté, faktury, účty. Bez nastavení a správy uživatelů.',
            self::CLEANER => 'Jen úklid. Další oblasti lze přidat přiřazením práv.',
        };
    }
}
