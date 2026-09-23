<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Customer\CustomerStaysProvider;
use App\Entity\Reservation;

/**
 * Hodnoty proměnných o vracejícím se hostovi. `returning_greeting` je hotová
 * věta v jazyce hosta a u prvního pobytu zůstane prázdná — řádek, na kterém
 * v šabloně stojí sama, ze zprávy vypadne a šablona se obejde bez podmínek.
 */
final class CustomerMessageContext
{
    /** @var array<string, string> jazyk => věta */
    public const array GREETING = [
        'cs' => 'Jsme rádi, že se k nám zase vracíte.',
        'en' => 'We are glad to welcome you back.',
    ];

    public function __construct(
        private readonly CustomerStaysProvider $stays,
        private readonly GuestLocaleResolver $guestLocale,
    ) {
    }

    /** @return array{stay_number: string, returning_greeting: string} */
    public function forReservation(Reservation $reservation): array
    {
        $stays = $this->stays->forReservation($reservation);
        $ordinal = $stays?->ordinal();
        $returning = $ordinal !== null && $ordinal > 1;
        $locale = $this->guestLocale->forReservation($reservation);

        return [
            'stay_number' => $ordinal !== null ? (string) $ordinal : '',
            'returning_greeting' => $returning ? (self::GREETING[$locale] ?? self::GREETING['en']) : '',
        ];
    }
}
