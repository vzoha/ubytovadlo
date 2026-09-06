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

/**
 * Kudy se k hostovi dostane zpráva. S e-mailem odejde poštou sama; host z OTA
 * bez e-mailu má jen chat portálu, kam text vloží ubytovatelka ručně.
 */
class GuestMessageDelivery
{
    public function byChat(Reservation $reservation): bool
    {
        return $reservation->getGuestContact()->getEmail() === null
            && $reservation->getChannel()->isOta();
    }
}
