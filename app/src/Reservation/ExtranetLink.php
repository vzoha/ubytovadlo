<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Reservation;

use App\Booking\BookingHotelId;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Repository\SettingRepository;

/**
 * Odkaz z rezervace rovnou na její stránku v extranetu prodejního kanálu.
 * Storno, změnu termínu i komunikaci s hostem řeší OTA u sebe — z Ubytovadla
 * se tam proklikneš na jeden klik místo hledání podle kódu.
 *
 * Airbnb stačí kód rezervace. Booking potřebuje navíc ID ubytování; sbírá se
 * z odkazu v e-mailu o nové rezervaci a dá se přepsat v nastavení připojení.
 */
final class ExtranetLink
{
    private const AIRBNB_RESERVATION = 'https://www.airbnb.com/hosting/reservations/details/%s';
    /** Potvrzující kód Airbnb — velká písmena a číslice, jak ho čte i parser e-mailů. */
    private const AIRBNB_CODE = '/^[A-Z0-9]{6,}$/';
    private const BOOKING_RESERVATION = 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/booking.html?res_id=%s&hotel_id=%s&lang=cs';

    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    /**
     * Adresa rezervace v extranetu, nebo null, když kanál extranet nemá
     * (web, přímá) nebo chybí kód rezervace či ID ubytování.
     */
    public function forReservation(Reservation $reservation): ?string
    {
        $externalId = $reservation->getExternalId();
        if ($externalId === null || $externalId === '') {
            return null;
        }

        return match ($reservation->getChannel()) {
            Channel::AIRBNB => $this->airbnbLink($externalId),
            Channel::BOOKING => $this->bookingLink($externalId),
            default => null,
        };
    }

    public function bookingHotelId(): string
    {
        return (string) $this->settings->getString(BookingHotelId::SETTING_KEY);
    }

    private function airbnbLink(string $externalId): ?string
    {
        // Rezervace bez potvrzujícího kódu (jen iCal blok) stránku v extranetu nemá.
        if (preg_match(self::AIRBNB_CODE, $externalId) !== 1) {
            return null;
        }

        return sprintf(self::AIRBNB_RESERVATION, rawurlencode($externalId));
    }

    private function bookingLink(string $externalId): ?string
    {
        $hotelId = $this->bookingHotelId();
        if ($hotelId === '') {
            return null;
        }

        return sprintf(self::BOOKING_RESERVATION, rawurlencode($externalId), rawurlencode($hotelId));
    }
}
