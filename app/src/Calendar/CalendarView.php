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
 * Kompletní podklad pro vykreslení kalendáře — sloupce dnů, řádky jednotek
 * a pod nimi servisní řádky. Osa i mřížka renderují týž objekt.
 */
final readonly class CalendarView
{
    /**
     * @param list<CalendarDay> $days
     * @param list<CalendarRow> $unitRows
     * @param list<CalendarRow> $serviceRows
     * @param int               $conflictingReservations kolik rezervací se překrývá s jinou
     */
    public function __construct(
        public CalendarRange $range,
        public array $days,
        public array $unitRows,
        public array $serviceRows,
        public int $conflictingReservations = 0,
    ) {
    }

    public function isEmpty(): bool
    {
        foreach ([...$this->unitRows, ...$this->serviceRows] as $row) {
            if (!$row->isEmpty()) {
                return false;
            }
        }

        return true;
    }
}
