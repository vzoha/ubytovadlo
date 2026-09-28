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
use App\ValueObject\PhoneNumber;

/**
 * Základ odkazů na WhatsApp a SMS hosta. Text zprávy jde před odesláním
 * upravit, parametr s textem proto doplní až prohlížeč.
 */
final class GuestPhoneLinks
{
    /** @return array{whatsapp: ?string, sms: ?string} */
    public static function forReservation(Reservation $reservation): array
    {
        $phone = PhoneNumber::tryFromString($reservation->getGuestContact()->getPhone());

        return [
            'whatsapp' => $phone !== null ? 'https://wa.me/' . $phone->whatsapp() : null,
            'sms' => $phone !== null ? 'sms:' . $phone->e164() : null,
        ];
    }
}
