<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ubyport;

use App\Entity\GuestDocument;
use App\Entity\Reservation;
use App\Repository\GuestDocumentRepository;
use App\Repository\ReservationRepository;

/**
 * Sestaví Ubyport frontu po rezervacích a zařadí každou do stavu nahlášení.
 * Sdílené Ubyport dashboardem (/ubyport) i widgetem na hlavním přehledu.
 */
final class UbyportQueue
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly GuestDocumentRepository $documents,
        private readonly ReportingDeadline $deadline,
    ) {
    }

    /**
     * @return list<UbyportRow>
     */
    public function rows(\DateTimeImmutable $today): array
    {
        $reservations = $this->reservations->findWithConfirmedForeigners();
        $foreignersByReservation = $this->documents->findConfirmedForeignersGroupedByReservation($reservations);

        $rows = [];
        foreach ($reservations as $reservation) {
            $foreigners = $foreignersByReservation[(int) $reservation->getId()] ?? [];
            if ($foreigners === []) {
                continue;
            }
            $rows[] = $this->buildRow($reservation, $foreigners, $today);
        }

        return $rows;
    }

    /**
     * @return list<GuestDocument>
     */
    public function foreignersOf(Reservation $reservation): array
    {
        return array_values(array_filter(
            $this->documents->findByReservation($reservation),
            static fn (GuestDocument $g): bool => !$g->isCzechCitizen() && $g->isConfirmedByGuest(),
        ));
    }

    /**
     * @param list<GuestDocument> $foreigners
     */
    private function buildRow(Reservation $reservation, array $foreigners, \DateTimeImmutable $today): UbyportRow
    {
        $missing = [];
        if ($reservation->getCheckOut() === null) {
            $missing[] = 'check-out';
        }
        foreach ($foreigners as $g) {
            if ($g->getNationalityCode() === null) {
                $missing[] = 'občanství';
            }
            if ($g->getDocumentNumber() === null) {
                $missing[] = 'číslo dokladu';
            }
        }
        $missing = array_values(array_unique($missing));
        $isComplete = $missing === [];

        $state = match (true) {
            $reservation->getUbyportReport()->getConfirmedAt() !== null => UbyportRow::STATE_REPORTED,
            $reservation->getUbyportReport()->getExportedAt() !== null => UbyportRow::STATE_AWAITING_RECEIPT,
            $isComplete => UbyportRow::STATE_TO_REPORT,
            default => UbyportRow::STATE_INCOMPLETE,
        };

        $deadline = $this->deadline->deadlineFor($reservation->getCheckIn());

        return new UbyportRow(
            reservation: $reservation,
            foreigners: $foreigners,
            missing: $missing,
            isComplete: $isComplete,
            state: $state,
            deadline: $deadline,
            daysLeft: $this->deadline->daysLeft($deadline, $today),
            deadlineState: $this->deadline->state($deadline, $today),
        );
    }
}
