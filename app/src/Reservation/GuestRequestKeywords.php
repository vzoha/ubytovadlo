<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Reservation;

/**
 * Centralni misto pro detekci pozadavku hosta v libovolnem textu
 * (nazev MotoPress sluzby, poznamka rezervace, radek z Booking extranetu).
 *
 * Udrzujeme jeden regex per pozadavek, aby se vsichni parseri shodli.
 */
final class GuestRequestKeywords
{
    private const PET_PATTERN = '/\b(pes|psa|psi|psem|pejsek|pejska|pejsky|štěn[\p{L}]*|fenku?|fenu|dog)\b/iu';
    private const BABY_COT_PATTERN = '/(postýlk|postylk|baby\s*cot|\bcot\b|kolébk|kolebk|crib)/iu';

    public static function mentionsPet(string $text): bool
    {
        return preg_match(self::PET_PATTERN, $text) === 1;
    }

    /**
     * Vrati matched substring (pro ulozeni do poznamky), nebo null.
     */
    public static function matchPet(string $text): ?string
    {
        if (preg_match(self::PET_PATTERN, $text, $m) === 1) {
            return trim($m[0]);
        }

        return null;
    }

    public static function mentionsBabyCot(string $text): bool
    {
        return preg_match(self::BABY_COT_PATTERN, $text) === 1;
    }
}
