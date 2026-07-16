<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Formatting;

use App\Formatting\CzechCalendar;
use PHPUnit\Framework\TestCase;

final class CzechCalendarTest extends TestCase
{
    public function testMonthName(): void
    {
        self::assertSame('leden', CzechCalendar::monthName(1));
        self::assertSame('prosinec', CzechCalendar::monthName(12));
        self::assertSame('', CzechCalendar::monthName(13));
    }

    public function testGenitiveMonthsMapNameToNumber(): void
    {
        $months = CzechCalendar::genitiveMonths();

        self::assertSame(1, $months['ledna']);
        self::assertSame(5, $months['května']);
        self::assertSame(12, $months['prosince']);
        self::assertCount(12, $months);
    }
}
