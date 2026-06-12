<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Vat;

use App\Entity\Reservation;

/**
 * Měsíční agregace DPH bez návaznosti na vystavené dokumenty (Booking PDF / Airbnb statement /
 * filing). Obsahuje jen primitivní součty + seznam rezervací, který se hodí v kontroleru
 * pro detailní pohled. Vyšší vrstvy (VatController, DashboardController) na to staví své
 * šablonové struktury.
 */
final readonly class VatMonthSummary
{
    /**
     * @param list<Reservation> $reservations
     */
    public function __construct(
        public int $year,
        public int $month,
        public array $reservations,
        public float $sumBaseCzk,
        public float $sumVatCzk,
        public bool $hasBookingReservations,
        public bool $hasAirbnbReservations,
        public float $bookingReservationSum,
        public float $airbnbReservationSum,
    ) {
    }
}
