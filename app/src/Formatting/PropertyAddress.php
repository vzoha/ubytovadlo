<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Formatting;

use App\Entity\AccommodationProfile;

/**
 * Adresa objektu na jeden řádek, jak ji čte host i provozovatel. Na vesnici bez
 * ulic nese adresu část obce („Lniště 30, 374 01 Slavče"), ve městě se uvádí
 * vedle ulice; číslo popisné a orientační odděluje lomítko.
 */
final class PropertyAddress
{
    public static function format(?AccommodationProfile $profile): string
    {
        // Nevyplněný profil ještě nemá povinná pole inicializovaná.
        if ($profile === null || $profile->getId() === null) {
            return '';
        }

        $number = implode('/', array_filter([$profile->getCp(), $profile->getCo()]));
        $street = trim(($profile->getUlice() ?: $profile->getCastObce() ?? '') . ' ' . $number);

        $lines = array_filter([
            $street,
            $profile->getUlice() ? $profile->getCastObce() : null,
            trim(self::postalCode($profile->getPsc()) . ' ' . $profile->getObec()),
        ]);

        return implode(', ', $lines);
    }

    /** PSČ se píše po trojicích a dvojicích: 37401 → 374 01. */
    private static function postalCode(?string $psc): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $psc);

        return \strlen($digits) === 5
            ? substr($digits, 0, 3) . ' ' . substr($digits, 3)
            : trim((string) $psc);
    }
}
