<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Booking;

/**
 * ID ubytování na Booking.com — číslo, kterým extranet adresuje objekt. Nese ho
 * každý odkaz do extranetu, takže se dá vyčíst z e-mailu o nové rezervaci
 * i z adresy, kterou majitel vlepí do nastavení.
 */
final class BookingHotelId
{
    public const SETTING_KEY = 'booking.hotel_id';

    private const IN_LINK = '/hotel_id=(\d+)/';

    /**
     * Přijme samotné číslo i celý odkaz, který e-mail láme na řádky — bílé znaky
     * padnou první, aby se číslo přelomené uprostřed přečetlo vcelku.
     */
    public static function normalize(string $raw): ?string
    {
        $compact = preg_replace('/\s+/', '', $raw) ?? '';
        if (preg_match(self::IN_LINK, $compact, $m) === 1) {
            return $m[1];
        }

        return $compact !== '' && ctype_digit($compact) ? $compact : null;
    }
}
