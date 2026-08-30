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
 * V jakém jazyce psát hostovi. Rozhoduje země z adresy hosta: česky pro
 * tuzemsko a Slovensko, jinak anglicky. Bez vyplněné země zůstává základní
 * jazyk — u českého ubytovatele je host bez adresy nejčastěji Čech.
 */
final class GuestLocaleResolver
{
    /** Země, kterým píšeme česky (ISO 3166-1 alfa-2). */
    private const CZECH_SPEAKING = ['CZ', 'SK'];

    public function forReservation(Reservation $reservation): string
    {
        $country = $reservation->getGuestAddress()->getCountry();
        if ($country === null || $country === '') {
            return MessageLocales::BASE;
        }

        return \in_array($country, self::CZECH_SPEAKING, true) ? MessageLocales::BASE : 'en';
    }
}
