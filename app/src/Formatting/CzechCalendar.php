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
 * České názvy měsíců — jedno místo pro celou appku (přehledy, e-maily),
 * ať se měsíc nepíše na každé stránce zvlášť.
 */
final class CzechCalendar
{
    /** @var list<string> 0-indexovaný seznam (leden = 0). */
    private const NAMES = [
        'leden', 'únor', 'březen', 'duben', 'květen', 'červen',
        'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec',
    ];

    /** Název měsíce (1 = leden), nebo prázdný řetězec mimo rozsah 1–12. */
    public static function monthName(int $month): string
    {
        return self::NAMES[$month - 1] ?? '';
    }

    /** @return list<string> Seznam 12 měsíců (0-indexovaný). */
    public static function names(): array
    {
        return self::NAMES;
    }

    /**
     * Genitiv názvů měsíců → číslo (1–12), jak je uvádějí české e-maily
     * ("29. května", "dne 3. dubna"). Pro parsování Airbnb i Booking notifikací.
     *
     * @return array<string, int>
     */
    public static function genitiveMonths(): array
    {
        return [
            'ledna' => 1, 'února' => 2, 'března' => 3, 'dubna' => 4,
            'května' => 5, 'června' => 6, 'července' => 7, 'srpna' => 8,
            'září' => 9, 'října' => 10, 'listopadu' => 11, 'prosince' => 12,
        ];
    }
}
