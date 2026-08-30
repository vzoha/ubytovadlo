<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Calendar;

/**
 * Jednotka, která se pronajímá — jeden řádek kalendáře. Dokud appka eviduje
 * jedno ubytování, je jednotka právě jedna; struktura je stejná pro N jednotek.
 */
final readonly class CalendarUnit
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
