<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Checkin;

use App\Entity\Reservation;
use App\Repository\ReservationRepository;

/**
 * Rezervace, kterou token z odkazu na online check-in právě otevírá. Mimo
 * časové okno (CheckinWindow) se chová jako neznámý token.
 */
final class CheckinAccess
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly CheckinWindow $window,
    ) {
    }

    public function reservationForToken(string $token): ?Reservation
    {
        $reservation = $this->reservations->findOneBy(['checkinToken' => $token]);

        return $reservation !== null && $this->window->allowsToken($reservation) ? $reservation : null;
    }
}
