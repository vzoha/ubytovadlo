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
use App\Repository\ReservationRepository;

final class CustomerStaysProvider
{
    public function __construct(private readonly ReservationRepository $reservations)
    {
    }

    /** Null, když rezervace zákazníka nemá. */
    public function forReservation(Reservation $reservation): ?CustomerStays
    {
        $customer = $reservation->getCustomer();
        if ($customer === null) {
            return null;
        }

        return new CustomerStays($reservation, $this->reservations->findStaysOfCustomer($customer));
    }
}
