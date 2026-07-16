<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Reservation\Event;

use App\Entity\Reservation;

/**
 * Vyslána, když se změní finanční stav rezervace — vystaví se faktura nebo
 * dorazí platba. Jádro tím jen oznamuje fakt "s penězi rezervace se něco stalo";
 * listener (pokud je) na něj reaguje. Slouží k uzavírání timeline akcí, jejichž
 * cíl je tím splněný, aniž by se čekalo na jejich naplánovaný čas.
 */
final class ReservationFinancialsChangedEvent
{
    public function __construct(
        public readonly Reservation $reservation,
    ) {
    }
}
