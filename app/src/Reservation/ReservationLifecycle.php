<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Reservation;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use Psr\Clock\ClockInterface;

/**
 * Stav rezervace odvozený z kalendáře: dokud pobyt nezačal, je „potvrzeno",
 * mezi příjezdem a odjezdem „probíhá", po odjezdu „dokončeno". Den odjezdu
 * patří ještě pobytu — host odjíždí dopoledne a akce k odjezdu mají doběhnout.
 *
 * Kalendář neřídí storno ani „doplnit údaje": storno je rozhodnutí majitele,
 * needs_details drží chybějící data. Obojí zůstává, dokud ho nezmění člověk
 * nebo import.
 */
final class ReservationLifecycle
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Stav, který rezervaci náleží dnes. Storno a nedotažené údaje nechává být.
     */
    public function derive(Reservation $reservation): ReservationStatus
    {
        $status = $reservation->getStatus();
        if (\in_array($status, [ReservationStatus::CANCELLED, ReservationStatus::NEEDS_DETAILS], true)) {
            return $status;
        }

        return $this->byCalendar($reservation);
    }

    /**
     * Stav po zrušení storna. Bez jména hosta se rezervace vrací mezi nedotažené
     * — typicky OTA rezervace, které údaje ještě nedorazily.
     */
    public function afterRestore(Reservation $reservation): ReservationStatus
    {
        if (trim((string) $reservation->getGuestName()) === '') {
            return ReservationStatus::NEEDS_DETAILS;
        }

        return $this->byCalendar($reservation);
    }

    /**
     * Porovnává kalendářní dny jako řetězce — termíny pobytu a „dnes" mohou
     * pocházet z různých časových pásem a půlnoc by se pak minula o hodiny.
     */
    private function byCalendar(Reservation $reservation): ReservationStatus
    {
        $today = $this->clock->now()->format('Y-m-d');
        $checkIn = $reservation->getCheckIn()->format('Y-m-d');
        $checkOut = ($reservation->getCheckOut() ?? $reservation->getCheckIn())->format('Y-m-d');

        if ($today < $checkIn) {
            return ReservationStatus::CONFIRMED;
        }
        if ($today > $checkOut) {
            return ReservationStatus::COMPLETED;
        }

        return ReservationStatus::IN_PROGRESS;
    }
}
