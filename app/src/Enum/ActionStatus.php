<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

enum ActionStatus: string
{
    case PLANNED = 'planned';
    case DONE = 'done';
    case CANCELLED = 'cancelled';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::PLANNED => 'Naplánováno',
            self::DONE => 'Hotovo',
            self::CANCELLED => 'Zrušeno',
            self::FAILED => 'Chyba',
            self::SKIPPED => 'Přeskočeno',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::PLANNED => 'info',
            self::DONE => 'success',
            self::CANCELLED => 'secondary',
            self::FAILED => 'danger',
            self::SKIPPED => 'secondary',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::PLANNED;
    }
}
