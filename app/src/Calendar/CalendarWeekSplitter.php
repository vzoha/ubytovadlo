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
 * Nakrájí kalendář po týdnech pro měsíční mřížku. Pás přes přelom týdne se
 * objeví v obou týdnech, každý se svou částí a s příznakem, že pokračuje.
 * Rozsah mřížky vždy začíná v pondělí a má celé týdny ({@see CalendarMonth::gridRange()}).
 */
final class CalendarWeekSplitter
{
    private const DAYS_IN_WEEK = 7;

    /** @return list<CalendarWeek> */
    public function split(CalendarView $view): array
    {
        $weeks = [];
        $markers = $this->serviceMarkers($view);
        for ($offset = 0; $offset < $view->range->days; $offset += self::DAYS_IN_WEEK) {
            $weeks[] = new CalendarWeek(
                \array_slice($view->days, $offset, self::DAYS_IN_WEEK),
                $this->clipRows($view->unitRows, $offset),
                $this->clipMarkers($markers, $offset),
            );
        }

        return $weeks;
    }

    /**
     * @param list<CalendarRow> $rows
     *
     * @return list<CalendarRow>
     */
    private function clipRows(array $rows, int $offset): array
    {
        $clipped = [];
        foreach ($rows as $row) {
            $lanes = [];
            foreach ($row->lanes as $lane) {
                $bars = [];
                foreach ($lane as $bar) {
                    $part = $bar->clipTo((float) $offset, (float) self::DAYS_IN_WEEK);
                    if ($part !== null) {
                        $bars[] = $part;
                    }
                }
                if ($bars !== []) {
                    $lanes[] = $bars;
                }
            }
            $clipped[] = new CalendarRow($row->title, $lanes);
        }

        return $clipped;
    }

    /**
     * V mřížce nemá servisní řádek kam jít — body ze všech servisních řádků
     * se kreslí přímo do buňky dne.
     *
     * @return list<CalendarMarker>
     */
    private function serviceMarkers(CalendarView $view): array
    {
        $markers = [];
        foreach ($view->serviceRows as $row) {
            foreach ($row->markers as $marker) {
                $markers[] = $marker;
            }
        }

        return $markers;
    }

    /**
     * @param list<CalendarMarker> $markers
     *
     * @return list<CalendarMarker>
     */
    private function clipMarkers(array $markers, int $offset): array
    {
        $inWeek = [];
        foreach ($markers as $marker) {
            $day = $marker->day - $offset;
            if ($day >= 0 && $day < self::DAYS_IN_WEEK) {
                $inWeek[] = new CalendarMarker($day, $marker->icon, $marker->label, $marker->tone, $marker->url);
            }
        }

        return $inWeek;
    }
}
