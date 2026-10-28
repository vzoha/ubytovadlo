<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Reservation;
use App\Entity\ReservationNote;
use App\Invoice\BalanceCalculator;
use App\Repository\GuestEmailRepository;
use App\Repository\ReservationNoteRepository;

/**
 * Podoba rezervace pro AI asistenta — sdílená všemi nástroji, ať model vidí
 * rezervaci vždy stejně. Souhrn stačí na přehled pobytů, detail přidá doplatek,
 * poznámky z časové osy a e-maily přiřazené k rezervaci.
 */
final class ReservationPresenter
{
    public function __construct(
        private readonly BalanceCalculator $balance,
        private readonly ReservationNoteRepository $notes,
        private readonly GuestEmailRepository $emails,
        private readonly GuestEmailPresenter $emailPresenter,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(Reservation $reservation): array
    {
        $checkOut = $reservation->getCheckOut();

        return [
            'id' => $reservation->getId(),
            'code' => $reservation->getExternalId() ?? $reservation->getMotopressExternalId(),
            'channel' => $reservation->getChannel()->label(),
            'status' => $reservation->getStatus()->label(),
            'checkIn' => $reservation->getCheckIn()->format('Y-m-d'),
            'checkOut' => $checkOut?->format('Y-m-d'),
            'nights' => $checkOut !== null ? (int) $reservation->getCheckIn()->diff($checkOut)->days : null,
            'guestName' => $reservation->getGuestName(),
            'guests' => [
                'adults' => $reservation->getGuestsAdult(),
                'children' => $reservation->getGuestsChild(),
                'infants' => $reservation->getGuestsInfant(),
            ],
            'price' => $reservation->getPriceTotal() !== null
                ? ['total' => $reservation->getPriceTotal(), 'currency' => $reservation->getPriceCurrency()]
                : null,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(Reservation $reservation): array
    {
        $contact = $reservation->getGuestContact();
        $balance = $this->balance->forReservation($reservation);

        return $this->summary($reservation) + [
            'checkInTime' => $reservation->getCheckInTime()?->format('H:i'),
            'checkOutTime' => $reservation->getCheckOutTime()?->format('H:i'),
            'email' => $contact->getEmail(),
            'phone' => $contact->getPhone(),
            'guestLanguage' => $reservation->getGuestLocale(),
            'pets' => $reservation->getPetsNote(),
            'notes' => $reservation->getNotes(),
            'onlineCheckinDone' => $reservation->getCheckinCompletedAt() !== null,
            'balance' => $balance !== null
                ? ['total' => $balance->total, 'paid' => $balance->paid, 'remaining' => $balance->remaining, 'currency' => 'CZK']
                : null,
            'timeline' => array_map(static fn (ReservationNote $note): array => [
                'date' => $note->getOccurredAt()->format('Y-m-d'),
                'type' => $note->getType()->label(),
                'text' => $note->getBody(),
            ], $this->notes->findForReservation($reservation)),
            'emails' => array_map($this->emailPresenter->headline(...), $this->emails->findForReservation($reservation)),
        ];
    }
}
