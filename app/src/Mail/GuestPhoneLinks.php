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
use App\Enum\ShareChannel;
use App\ValueObject\PhoneNumber;

/**
 * Odkaz, který otevře WhatsApp nebo SMS na telefon hosta s předvyplněným
 * textem. Text se dá upravit ještě v aplikaci před odesláním.
 */
final class GuestPhoneLinks
{
    /** null, když host nemá použitelné číslo nebo kanál nejde přes telefon. */
    public static function compose(Reservation $reservation, ShareChannel $channel, string $text): ?string
    {
        $phone = PhoneNumber::tryFromString($reservation->getGuestContact()->getPhone());
        if ($phone === null) {
            return null;
        }

        return match ($channel) {
            ShareChannel::WHATSAPP => 'https://wa.me/' . $phone->whatsapp() . '?text=' . rawurlencode($text),
            ShareChannel::SMS => 'sms:' . $phone->e164() . '?body=' . rawurlencode($text),
            default => null,
        };
    }
}
