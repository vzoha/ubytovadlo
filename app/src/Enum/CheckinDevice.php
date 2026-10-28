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
 * Druh zařízení, na kterém host otevřel online check-in.
 */
enum CheckinDevice: string
{
    case MOBILE = 'mobile';
    case TABLET = 'tablet';
    case DESKTOP = 'desktop';

    public static function fromUserAgent(string $userAgent): self
    {
        // iPad hlásí i „Mobile", Android tablet naopak „Mobile" nemá — proto toto pořadí.
        return match (true) {
            preg_match('/iPad|Tablet/i', $userAgent) === 1 => self::TABLET,
            preg_match('/Mobi|iPhone/i', $userAgent) === 1 => self::MOBILE,
            preg_match('/Android/i', $userAgent) === 1 => self::TABLET,
            default => self::DESKTOP,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::MOBILE => 'mobil',
            self::TABLET => 'tablet',
            self::DESKTOP => 'počítač',
        };
    }
}
