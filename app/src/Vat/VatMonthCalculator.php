<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Vat;

use App\Enum\Channel;
use App\Repository\ReservationRepository;

/**
 * Sečte DPH měsíce z rezervací (reverse charge nad provizí OTA).
 *
 * Sazba 21 % se aplikuje až na součet základů — sčítání zaokrouhlených DPH per rezervaci by
 * na drobných číslech ujelo o haléře.
 */
final class VatMonthCalculator
{
    public function __construct(private readonly ReservationRepository $reservations)
    {
    }

    public function summarize(int $year, int $month): VatMonthSummary
    {
        $reservations = $this->reservations->findCommissionableByCheckoutMonth($year, $month);

        $sumBase = 0.0;
        $bookingSum = 0.0;
        $airbnbSum = 0.0;
        foreach ($reservations as $r) {
            $sumBase += (float) ($r->getVatBaseCzk() ?? 0);
            $commission = (float) ($r->getCommissionAmount() ?? 0);
            match ($r->getChannel()) {
                Channel::BOOKING => $bookingSum += $commission,
                Channel::AIRBNB => $airbnbSum += $commission,
                default => null,
            };
        }

        return new VatMonthSummary(
            year: $year,
            month: $month,
            reservations: $reservations,
            sumBaseCzk: $sumBase,
            sumVatCzk: round($sumBase * VatCalculator::VAT_RATE, 2),
            hasBookingReservations: $bookingSum > 0.0,
            hasAirbnbReservations: $airbnbSum > 0.0,
            bookingReservationSum: $bookingSum,
            airbnbReservationSum: $airbnbSum,
        );
    }
}
