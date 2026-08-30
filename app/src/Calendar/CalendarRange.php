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
 * Souvislý úsek dní, na který se kalendář kreslí. Pozice v rozsahu se počítá
 * ve dnech od začátku — celé číslo je půlnoc, `.5` poledne. Půlden je nosný:
 * host přijíždí odpoledne a odjíždí dopoledne, takže den odjezdu jednoho
 * a příjezdu druhého se v jednom sloupci potkají, aniž by šlo o kolizi.
 */
final readonly class CalendarRange
{
    public function __construct(
        public \DateTimeImmutable $start,
        public int $days,
    ) {
    }

    public function endExclusive(): \DateTimeImmutable
    {
        return $this->start->modify(sprintf('+%d days', $this->days));
    }

    /**
     * Pozice data na ose ve dnech od začátku rozsahu. Mimo rozsah vrací
     * záporné číslo nebo číslo větší než `days` — ořez si dělá volající.
     */
    public function offsetOf(\DateTimeImmutable $date): int
    {
        $from = $this->start->setTime(0, 0);
        $to = $date->setTime(0, 0);
        $diff = (int) $from->diff($to)->days;

        return $to < $from ? -$diff : $diff;
    }

    public function contains(\DateTimeImmutable $date): bool
    {
        $offset = $this->offsetOf($date);

        return $offset >= 0 && $offset < $this->days;
    }
}
