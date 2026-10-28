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
 * Jak host v online check-inu přečetl strojově čitelnou zónu dokladu.
 */
enum DocumentReadSource: string
{
    case LIVE = 'live';
    case PHOTO = 'photo';
    case MANUAL = 'manual';

    /** Hodnota z prohlížeče; neznámá nebo chybějící je null. */
    public static function fromInput(mixed $value): ?self
    {
        return \is_string($value) ? self::tryFrom($value) : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::LIVE => 'živá kamera',
            self::PHOTO => 'fotka',
            self::MANUAL => 'opsaný kód',
        };
    }
}
