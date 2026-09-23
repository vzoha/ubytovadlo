<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Repository\ReservationRepository;

final class CustomerStaysProvider
{
    public function __construct(private readonly ReservationRepository $reservations)
    {
    }

    /** Null, když rezervace zákazníka nemá (nebo ho ještě nemá uloženého). */
    public function forReservation(Reservation $reservation): ?CustomerStays
    {
        $customer = $reservation->getCustomer();
        if ($customer?->getId() === null) {
            return null;
        }

        $stays = array_filter(
            $this->reservations->findAllOfCustomer($customer),
            static fn (Reservation $stay): bool => $stay->getStatus() !== ReservationStatus::CANCELLED,
        );

        return new CustomerStays($reservation, array_reverse(array_values($stays)));
    }
}
