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
}
