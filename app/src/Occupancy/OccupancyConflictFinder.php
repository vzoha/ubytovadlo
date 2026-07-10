<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Occupancy;

use App\Entity\Reservation;

/**
 * Najde překrývající se aktivní rezervace (dvojí obsazení termínu). Pobyt je
 * půlotevřený interval [příjezd; odjezd) — odjezd jednoho hosta ve stejný den,
 * kdy přijíždí druhý, NEkoliduje. Bez odjezdu bereme jednu noc (příjezd + 1 den).
 *
 * Čistá logika bez DB/HTTP; funguje nezávisle na dostupnosti iCal feedů.
 */
final class OccupancyConflictFinder
{
    /**
     * @param Reservation[] $reservations řazené podle příjezdu (viz ReservationRepository::findActiveForOccupancy)
     *
     * @return OccupancyConflict[]
     */
    public function find(array $reservations): array
    {
        $reservations = array_values($reservations);
        usort($reservations, static fn (Reservation $a, Reservation $b): int => $a->getCheckIn() <=> $b->getCheckIn());

        $conflicts = [];
        $count = \count($reservations);
        for ($i = 0; $i < $count; $i++) {
            $a = $reservations[$i];
            $endA = self::end($a);
            for ($j = $i + 1; $j < $count; $j++) {
                $b = $reservations[$j];
                // Seřazeno podle příjezdu: jakmile další příjezd není před koncem A,
                // žádná pozdější rezervace už s A kolidovat nemůže.
                if ($b->getCheckIn() >= $endA) {
                    break;
                }
                $overlapTo = min($endA, self::end($b));
                if ($b->getCheckIn() < $overlapTo) {
                    $conflicts[] = new OccupancyConflict($a, $b, $b->getCheckIn(), $overlapTo);
                }
            }
        }

        return $conflicts;
    }

    private static function end(Reservation $reservation): \DateTimeImmutable
    {
        return $reservation->getCheckOut() ?? $reservation->getCheckIn()->modify('+1 day');
    }
}
