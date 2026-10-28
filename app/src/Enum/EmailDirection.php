<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

/** Směr e-mailu v korespondenci s hostem. */
enum EmailDirection: string
{
    case INBOUND = 'in';
    case OUTBOUND = 'out';

    public function label(): string
    {
        return match ($this) {
            self::INBOUND => 'E-mail od hosta',
            self::OUTBOUND => 'E-mail hostovi',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::INBOUND => '📨',
            self::OUTBOUND => '📤',
        };
    }
}
