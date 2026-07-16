<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Formatting;

/**
 * Český název země podle ISO kódu (alpha-2) pro fakturační adresu. Neznámý kód
 * vrací beze změny (velkými písmeny) — na faktuře je pořád srozumitelnější kód
 * než prázdno.
 */
final class CountryNames
{
    private const NAMES = [
        'DE' => 'Německo',
        'SK' => 'Slovensko',
        'AT' => 'Rakousko',
        'PL' => 'Polsko',
        'HU' => 'Maďarsko',
        'NL' => 'Nizozemsko',
        'BE' => 'Belgie',
        'FR' => 'Francie',
        'IT' => 'Itálie',
        'ES' => 'Španělsko',
        'GB' => 'Velká Británie',
        'IE' => 'Irsko',
        'DK' => 'Dánsko',
        'SE' => 'Švédsko',
        'NO' => 'Norsko',
        'FI' => 'Finsko',
        'CH' => 'Švýcarsko',
        'US' => 'USA',
    ];

    public static function czech(string $iso): string
    {
        $code = strtoupper($iso);

        return self::NAMES[$code] ?? $code;
    }
}
