<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\BarSpan;
use App\Calendar\CalendarRange;
use PHPUnit\Framework\TestCase;

final class BarSpanTest extends TestCase
{
    private const SEPTEMBER = '2026-09-01';

    public function testStayStartsAtNoonOfArrivalAndEndsAtNoonOfDeparture(): void
    {
        $span = $this->span('2026-09-03', '2026-09-06');

        self::assertNotNull($span);
        self::assertSame(2.5, $span->start);
        self::assertSame(5.5, $span->end);
        self::assertFalse($span->continuesBefore);
        self::assertFalse($span->continuesAfter);
    }

    public function testDepartureAndArrivalOnSameDayDoNotOverlap(): void
    {
        $leaving = $this->span('2026-09-01', '2026-09-05');
        $arriving = $this->span('2026-09-05', '2026-09-09');

        self::assertNotNull($leaving);
        self::assertNotNull($arriving);
        self::assertFalse($leaving->overlaps($arriving));
    }

    public function testRealOverlapIsDetected(): void
    {
        $first = $this->span('2026-09-01', '2026-09-06');
        $second = $this->span('2026-09-05', '2026-09-09');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertTrue($first->overlaps($second));
    }

    public function testStayFromPreviousMonthIsCutToRangeStart(): void
    {
        $span = $this->span('2026-08-28', '2026-09-03');

        self::assertNotNull($span);
        self::assertSame(0.0, $span->start);
        self::assertSame(2.5, $span->end);
        self::assertTrue($span->continuesBefore);
        self::assertFalse($span->continuesAfter);
    }

    public function testStayIntoNextMonthIsCutToRangeEnd(): void
    {
        $span = $this->span('2026-09-28', '2026-10-04');

        self::assertNotNull($span);
        self::assertSame(27.5, $span->start);
        self::assertSame(30.0, $span->end);
        self::assertTrue($span->continuesAfter);
    }

    public function testStayOutsideRangeHasNoSpan(): void
    {
        self::assertNull($this->span('2026-07-01', '2026-07-05'));
    }

    public function testDepartureMorningOfFirstDayStillShows(): void
    {
        // Host odjíždí 1. 9. dopoledne — v měsíci zabírá první půlden.
        $span = $this->span('2026-08-30', '2026-09-01');

        self::assertNotNull($span);
        self::assertSame(0.0, $span->start);
        self::assertSame(0.5, $span->end);
    }

    public function testClipToWeekRebasesOffsetsAndMarksContinuation(): void
    {
        $span = $this->span('2026-09-03', '2026-09-10');
        self::assertNotNull($span);

        $firstWeek = $span->clipTo(0.0, 7.0);
        $secondWeek = $span->clipTo(7.0, 7.0);

        self::assertNotNull($firstWeek);
        self::assertSame(2.5, $firstWeek->start);
        self::assertSame(7.0, $firstWeek->end);
        self::assertTrue($firstWeek->continuesAfter);

        self::assertNotNull($secondWeek);
        self::assertSame(0.0, $secondWeek->start);
        self::assertSame(2.5, $secondWeek->end);
        self::assertTrue($secondWeek->continuesBefore);
    }

    public function testClipOutsideWindowReturnsNothing(): void
    {
        $span = $this->span('2026-09-03', '2026-09-06');
        self::assertNotNull($span);

        self::assertNull($span->clipTo(14.0, 7.0));
    }

    public function testPercentagesMapOntoRangeWidth(): void
    {
        $span = new BarSpan(0.0, 5.0, false, false);

        self::assertSame(0.0, $span->leftPercent(10));
        self::assertSame(50.0, $span->widthPercent(10));
    }

    private function span(string $from, string $to): ?BarSpan
    {
        $range = new CalendarRange(new \DateTimeImmutable(self::SEPTEMBER), 30);

        return BarSpan::forStay($range, new \DateTimeImmutable($from), new \DateTimeImmutable($to));
    }
}
