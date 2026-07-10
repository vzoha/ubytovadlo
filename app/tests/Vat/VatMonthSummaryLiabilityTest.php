<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Vat;

use App\Enum\TaxProfile;
use App\Vat\VatMonthSummary;
use PHPUnit\Framework\TestCase;

final class VatMonthSummaryLiabilityTest extends TestCase
{
    public function testIdentifiedPersonOwesOnlyReverseCharge(): void
    {
        // RC 210 z provize, žádná výstupní DPH.
        $liability = $this->summary(reverseChargeVat: 210.0, outputVat: 0.0)->liability(TaxProfile::IDENTIFIED_PERSON);

        self::assertSame(0.0, $liability->outputVatCzk);
        self::assertSame(210.0, $liability->reverseChargeVatCzk);
        self::assertSame(0.0, $liability->deductibleVatCzk);
        self::assertSame(210.0, $liability->totalCzk);
    }

    public function testVatPayerDeductsReverseChargeSoOnlyOutputRemains(): void
    {
        // Výstup 900 (faktury hostům) + RC 210 − odpočet 210 = 900.
        $liability = $this->summary(reverseChargeVat: 210.0, outputVat: 900.0)->liability(TaxProfile::VAT_PAYER);

        self::assertSame(900.0, $liability->outputVatCzk);
        self::assertSame(210.0, $liability->reverseChargeVatCzk);
        self::assertSame(210.0, $liability->deductibleVatCzk);
        self::assertSame(900.0, $liability->totalCzk);
    }

    public function testNonPayerHasNoLiability(): void
    {
        $liability = $this->summary(reverseChargeVat: 210.0, outputVat: 0.0)->liability(TaxProfile::NON_PAYER);

        self::assertSame(0.0, $liability->totalCzk);
        self::assertSame(0.0, $liability->reverseChargeVatCzk);
    }

    private function summary(float $reverseChargeVat, float $outputVat): VatMonthSummary
    {
        return new VatMonthSummary(
            year: 2026,
            month: 5,
            reservations: [],
            sumBaseCzk: $reverseChargeVat / 0.21,
            sumVatCzk: $reverseChargeVat,
            hasBookingReservations: false,
            hasAirbnbReservations: false,
            bookingReservationSum: 0.0,
            airbnbReservationSum: 0.0,
            outputBaseCzk: $outputVat / 0.12,
            outputVatCzk: $outputVat,
        );
    }
}
