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
 * Jak se počítá příští termín:
 * - INTERVAL — interval běží od data posledního provedení (revize, servis),
 * - FIXED_DATE — termín je daný kalendářem a posouvá se o interval bez ohledu
 *   na to, kdy se úkon udělal (daňové přiznání, odvod poplatku obci),
 * - ONE_OFF — jediný termín; splněním se hlídání uzavře.
 */
enum TaskRecurrence: string
{
    case INTERVAL = 'interval';
    case FIXED_DATE = 'fixed_date';
    case ONE_OFF = 'one_off';

    public function label(): string
    {
        return match ($this) {
            self::INTERVAL => 'Od posledního provedení',
            self::FIXED_DATE => 'Pevné datum v kalendáři',
            self::ONE_OFF => 'Jednorázový termín',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::INTERVAL => 'Příští termín se počítá od data, kdy byl úkon naposled proveden.',
            self::FIXED_DATE => 'Termín se drží kalendáře a posune se o interval, i když se úkon udělal dřív nebo později.',
            self::ONE_OFF => 'Termín se hlídá jednou, po splnění se úkon uzavře.',
        };
    }

    public function repeats(): bool
    {
        return $this !== self::ONE_OFF;
    }
}
