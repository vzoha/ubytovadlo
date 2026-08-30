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
 * Řádek kalendáře — jednotka s pásy rezervací, nebo servisní řádek s body
 * (úklid, termíny). Pásy jsou rozdělené do drah: co se překrývá, dostane
 * vlastní dráhu, takže dvojí prodej je vidět jako dva pásy nad sebou.
 */
final readonly class CalendarRow
{
    /**
     * @param list<list<CalendarBar>> $lanes
     * @param list<CalendarMarker>    $markers
     */
    public function __construct(
        public string $title,
        public array $lanes = [],
        public array $markers = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->lanes === [] && $this->markers === [];
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
