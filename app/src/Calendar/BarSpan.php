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
 * Geometrie pásu na ose: kde v rozsahu začíná a končí, ve dnech od jeho začátku.
 * Příjezd i odjezd padá na poledne (`.5`), takže odjezd jednoho hosta a příjezd
 * druhého ve stejný den zabírají každý svou polovinu sloupce.
 *
 * `continuesBefore`/`continuesAfter` říkají, že pobyt přesahuje mimo kreslené
 * okno — pás se pak na té straně nezakulacuje, aby bylo vidět pokračování.
 */
final readonly class BarSpan
{
    public function __construct(
        public float $start,
        public float $end,
        public bool $continuesBefore,
        public bool $continuesAfter,
    ) {
    }

    /**
     * Pobyt [$from; $to) promítnutý do rozsahu. Vrací null, když do rozsahu
     * nezasahuje ani půldnem.
     */
    public static function forStay(CalendarRange $range, \DateTimeImmutable $from, \DateTimeImmutable $to): ?self
    {
        $arrival = $range->offsetOf($from) + 0.5;
        $departure = $range->offsetOf($to) + 0.5;
        $limit = (float) $range->days;

        $start = max(0.0, $arrival);
        $end = min($limit, $departure);
        if ($end <= $start) {
            return null;
        }

        return new self($start, $end, $arrival < 0.0, $departure > $limit);
    }

    /** Část pásu spadající do okna, přepočtená na jeho začátek; null, když do okna nesahá. */
    public function clipTo(float $windowStart, float $windowDays): ?self
    {
        $windowEnd = $windowStart + $windowDays;
        $start = max($this->start, $windowStart);
        $end = min($this->end, $windowEnd);
        if ($end <= $start) {
            return null;
        }

        return new self(
            $start - $windowStart,
            $end - $windowStart,
            $this->continuesBefore || $this->start < $windowStart,
            $this->continuesAfter || $this->end > $windowEnd,
        );
    }

    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $other->start < $this->end;
    }

    public function leftPercent(int $days): float
    {
        return round($this->start / $days * 100, 4);
    }

    public function widthPercent(int $days): float
    {
        return round(($this->end - $this->start) / $days * 100, 4);
    }
}
