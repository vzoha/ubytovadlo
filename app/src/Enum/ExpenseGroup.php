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
 * Skupina výdajové kategorie — dělí výdaje na provoz ubytování (reálný náklad,
 * vstupuje do zisku v Ekonomice) a osobní/finanční odliv (splátka úvěru, výběr
 * majitele — jen snižuje stav účtu, není náklad ubytování).
 */
enum ExpenseGroup: string
{
    case OPERATING = 'operating';
    case PERSONAL = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::OPERATING => 'Provoz ubytování',
            self::PERSONAL => 'Osobní a finanční',
        };
    }
}
