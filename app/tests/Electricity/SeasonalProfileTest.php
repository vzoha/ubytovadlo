<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Electricity;

use App\Service\Electricity\SeasonalProfile;
use PHPUnit\Framework\TestCase;

class SeasonalProfileTest extends TestCase
{
    public function testWinterIsHeavierThanSummer(): void
    {
        self::assertGreaterThan(
            SeasonalProfile::factorForMonth(7),
            SeasonalProfile::factorForMonth(1),
        );
        self::assertGreaterThan(2.0, SeasonalProfile::factorForMonth(1));
        self::assertLessThan(0.5, SeasonalProfile::factorForMonth(7));
    }

    public function testStayWeightIsSumOfNightlyFactors(): void
    {
        // 2 noci v lednu
        $checkIn = new \DateTimeImmutable('2026-01-10');
        $checkOut = new \DateTimeImmutable('2026-01-12');
        $w = SeasonalProfile::weightForStay($checkIn, $checkOut);
        self::assertEqualsWithDelta(2 * SeasonalProfile::factorForMonth(1), $w, 0.001);
    }

    public function testStayCrossingMonthsAveragesAcrossNights(): void
    {
        // 31.12. → 2.1. = noc 31.12 (prosinec) + noc 1.1 (leden)
        $checkIn = new \DateTimeImmutable('2025-12-31');
        $checkOut = new \DateTimeImmutable('2026-01-02');
        $w = SeasonalProfile::weightForStay($checkIn, $checkOut);
        $expected = SeasonalProfile::factorForMonth(12) + SeasonalProfile::factorForMonth(1);
        self::assertEqualsWithDelta($expected, $w, 0.001);
    }

    public function testZeroNightsReturnsZeroWeight(): void
    {
        $d = new \DateTimeImmutable('2026-06-01');
        self::assertSame(0.0, SeasonalProfile::weightForStay($d, $d));
    }

    public function testLargeGroupAddsFactor(): void
    {
        $in = new \DateTimeImmutable('2026-01-10');
        $out = new \DateTimeImmutable('2026-01-12');
        $w2 = SeasonalProfile::weightForStay($in, $out, 2);
        $w4 = SeasonalProfile::weightForStay($in, $out, 4);
        self::assertEqualsWithDelta($w2 * 1.20, $w4, 0.001);
    }

    public function testGuestsBelowThreeNoBoost(): void
    {
        self::assertSame(1.0, SeasonalProfile::factorForGuests(0));
        self::assertSame(1.0, SeasonalProfile::factorForGuests(1));
        self::assertSame(1.0, SeasonalProfile::factorForGuests(2));
        self::assertSame(1.20, SeasonalProfile::factorForGuests(3));
        self::assertSame(1.20, SeasonalProfile::factorForGuests(5));
    }
}
