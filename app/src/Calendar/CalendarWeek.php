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
 * Jeden týden mřížky: sedm sloupců a k nim pásy oříznuté na tento týden.
 * Pás přes přelom týdne se objeví v obou — každý se svou částí.
 */
final readonly class CalendarWeek
{
    /**
     * @param list<CalendarDay>    $days
     * @param list<CalendarRow>    $rows
     * @param list<CalendarMarker> $markers
     */
    public function __construct(
        public array $days,
        public array $rows,
        public array $markers,
    ) {
    }

    /** @return list<CalendarMarker> */
    public function markersOn(int $day): array
    {
        return array_values(array_filter(
            $this->markers,
            static fn (CalendarMarker $marker): bool => $marker->day === $day,
        ));
    }
}
