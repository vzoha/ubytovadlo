<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

enum Channel: string
{
    case WEB = 'web';
    case BOOKING = 'booking';
    case AIRBNB = 'airbnb';
    case ECHALUPY = 'echalupy';
    case CS_CHALUPY = 'cs_chalupy';
    /** Přímá rezervace zadaná ručně (telefon, e-mail, osobně) — bez OTA ani webového funnelu. */
    case DIRECT = 'direct';

    public function label(): string
    {
        return match ($this) {
            self::WEB => 'Web',
            self::BOOKING => 'Booking.com',
            self::AIRBNB => 'Airbnb',
            self::ECHALUPY => 'eChalupy',
            self::CS_CHALUPY => 'CS chalupy',
            self::DIRECT => 'Přímá',
        };
    }

    /**
     * Provizní OTA, kde rezervaci zakládá portál a údaje hosta i provizi tahá
     * majitelka z extranetu (Booking, Airbnb). Řídí `needs_details` tok, provizi
     * a reverse-charge DPH.
     *
     * eChalupy a CS chalupy sem nepatří — jsou to jen iCal feedy obsazenosti bez
     * údajů hosta a provize.
     */
    public function isOta(): bool
    {
        return match ($this) {
            self::BOOKING, self::AIRBNB => true,
            default => false,
        };
    }
}
