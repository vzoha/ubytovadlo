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
use App\Enum\TaxProfile;

/**
 * Měsíční agregace DPH bez návaznosti na vystavené dokumenty (Booking PDF / Airbnb statement /
 * filing). Obsahuje jen primitivní součty + seznam rezervací, který se hodí v kontroleru
 * pro detailní pohled. Vyšší vrstvy (VatController, DashboardController) na to staví své
 * šablonové struktury.
 *
 * `sumBaseCzk`/`sumVatCzk` = reverse charge z provizí OTA (21 %). `outputBaseCzk`/`outputVatCzk`
 * = výstupní DPH z faktur hostům vystavených v měsíci (jen u plátce; jinak 0).
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
        public float $outputBaseCzk = 0.0,
        public float $outputVatCzk = 0.0,
    ) {
    }

    /**
     * Daňová povinnost měsíce podle daňového profilu:
     *  - identifikovaná osoba: jen reverse charge z provize, bez odpočtu,
     *  - plátce: výstupní DPH z faktur + RC − odpočet RC (RC se vyruší → zbývá výstup),
     *  - neplátce: nulová (modul se stejně skrývá).
     */
    public function liability(TaxProfile $profile): VatLiability
    {
        $reverseCharge = $this->sumVatCzk;
        $output = $this->outputVatCzk;

        return match ($profile) {
            TaxProfile::VAT_PAYER => new VatLiability($output, $reverseCharge, $reverseCharge, round($output, 2)),
            TaxProfile::IDENTIFIED_PERSON => new VatLiability(0.0, $reverseCharge, 0.0, round($reverseCharge, 2)),
            TaxProfile::NON_PAYER => new VatLiability(0.0, 0.0, 0.0, 0.0),
        };
    }
}
