<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Entity\Reservation;
use App\Enum\Channel;

/**
 * Neutrální ukázková rezervace pro náhled a testovací odeslání šablon. Není
 * v DB — dostane jen synthetické id, aby případné dotazy navázané na rezervaci
 * (doplatek) vrátily prázdno místo chyby, a aby v náhledu nebylo žádné reálné PII.
 */
final class SampleReservationFactory
{
    public function create(): Reservation
    {
        $checkIn = (new \DateTimeImmutable('today'))->modify('+7 days')->setTime(15, 0);
        $checkOut = $checkIn->modify('+3 days')->setTime(10, 0);

        $reservation = new Reservation(Channel::WEB, $checkIn);
        $reservation
            ->setCheckOut($checkOut)
            ->setCheckInTime($checkIn)
            ->setCheckOutTime($checkOut)
            ->setGuestName('Jan Ukázka')
            ->setGuestEmail('host@example.com')
            ->setGuestsAdult(2)
            ->setPriceTotal('6000')
            ->setCheckinToken(str_repeat('0123456789abcdef', 4)); // 64-znakový hex, ať {{ checkin_url }} v náhledu vede na reálnou adresu

        $this->assignSyntheticId($reservation);

        return $reservation;
    }

    /** Synthetické id, ať dotazy `WHERE reservation = :r` vrátí prázdno místo chyby. */
    private function assignSyntheticId(Reservation $reservation): void
    {
        $ref = new \ReflectionProperty(Reservation::class, 'id');
        $ref->setValue($reservation, 0);
    }
}
