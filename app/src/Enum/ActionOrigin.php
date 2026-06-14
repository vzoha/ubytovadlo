<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

enum ActionOrigin: string
{
    /** Založeno automaticky plánovačem (ReservationActionPlanner). */
    case AUTO = 'auto';
    /** Přidáno ručně majitelkou. */
    case MANUAL = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::AUTO => 'automaticky',
            self::MANUAL => 'ručně',
        };
    }
}
