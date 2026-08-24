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
 * Jednotka intervalu opakování. Dny pokryjí krátké cykly (prohlídka výtahu po
 * 14 dnech), měsíce půlroční a roční lhůty, roky víceleté revize.
 */
enum TaskIntervalUnit: string
{
    case DAY = 'day';
    case MONTH = 'month';
    case YEAR = 'year';

    public function label(): string
    {
        return match ($this) {
            self::DAY => 'dní',
            self::MONTH => 'měsíců',
            self::YEAR => 'let',
        };
    }
}
