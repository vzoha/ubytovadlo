<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\MotoPress;

/**
 * Pozna, jestli je MotoPress booking realny webovy prodej, nebo iCal blok z Booking/Airbnb.
 *
 * Kriteria:
 *  - imported = false  -> WEB
 *  - imported = true + ical_prodid obsahuje "Airbnb"  -> IMPORTED_AIRBNB (vytahne confirmation code z ical_description)
 *  - imported = true + ical_prodid obsahuje "booking.com" -> IMPORTED_BOOKING
 *  - jinak -> IMPORTED_UNKNOWN
 */
class MotoPressBookingClassifier
{
    private const AIRBNB_CODE_PATTERN = '~/hosting/reservations/details/([A-Z0-9]+)~';

    /**
     * @param array<string, mixed> $data
     */
    public function classify(array $data): ClassifiedBooking
    {
        $imported = (bool) ($data['imported'] ?? false);
        if (!$imported) {
            return new ClassifiedBooking(MotoPressBookingKind::WEB);
        }

        $prodid = strtolower((string) ($data['ical_prodid'] ?? ''));

        if (str_contains($prodid, 'airbnb')) {
            return new ClassifiedBooking(
                MotoPressBookingKind::IMPORTED_AIRBNB,
                $this->extractAirbnbCode((string) ($data['ical_description'] ?? '')),
            );
        }

        if (str_contains($prodid, 'booking.com')) {
            return new ClassifiedBooking(MotoPressBookingKind::IMPORTED_BOOKING);
        }

        return new ClassifiedBooking(MotoPressBookingKind::IMPORTED_UNKNOWN);
    }

    private function extractAirbnbCode(string $description): ?string
    {
        if ($description === '') {
            return null;
        }
        if (preg_match(self::AIRBNB_CODE_PATTERN, $description, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}
