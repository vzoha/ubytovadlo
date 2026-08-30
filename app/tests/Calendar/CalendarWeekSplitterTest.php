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
use App\Calendar\CalendarBar;
use App\Calendar\CalendarDay;
use App\Calendar\CalendarMarker;
use App\Calendar\CalendarRange;
use App\Calendar\CalendarRow;
use App\Calendar\CalendarView;
use App\Calendar\CalendarWeekSplitter;
use PHPUnit\Framework\TestCase;

final class CalendarWeekSplitterTest extends TestCase
{
    public function testStayAcrossWeekendAppearsInBothWeeks(): void
    {
        $view = $this->view(new BarSpan(3.5, 9.5, false, false), []);

        $weeks = (new CalendarWeekSplitter())->split($view);

        self::assertCount(2, $weeks);
        $first = $weeks[0]->rows[0]->lanes[0][0];
        $second = $weeks[1]->rows[0]->lanes[0][0];

        self::assertSame(3.5, $first->span->start);
        self::assertSame(7.0, $first->span->end);
        self::assertTrue($first->span->continuesAfter);

        self::assertSame(0.0, $second->span->start);
        self::assertSame(2.5, $second->span->end);
        self::assertTrue($second->span->continuesBefore);
    }

    public function testWeekWithoutBarsKeepsEmptyRow(): void
    {
        $view = $this->view(new BarSpan(1.5, 3.5, false, false), []);

        $weeks = (new CalendarWeekSplitter())->split($view);

        self::assertSame([], $weeks[1]->rows[0]->lanes);
    }

    public function testMarkersLandInTheirWeekWithLocalDay(): void
    {
        $view = $this->view(
            new BarSpan(1.5, 3.5, false, false),
            [new CalendarMarker(9, '🧹', 'Úklid', 'secondary'), new CalendarMarker(2, '⚠', 'Revize', 'danger')],
        );

        $weeks = (new CalendarWeekSplitter())->split($view);

        self::assertCount(1, $weeks[0]->markersOn(2));
        self::assertSame('Revize', $weeks[0]->markersOn(2)[0]->label);
        self::assertCount(1, $weeks[1]->markersOn(2));
        self::assertSame('Úklid', $weeks[1]->markersOn(2)[0]->label);
    }

    /**
     * Dva celé týdny od pondělí, jeden řádek jednotky s jedním pásem.
     *
     * @param list<CalendarMarker> $markers
     */
    private function view(BarSpan $span, array $markers): CalendarView
    {
        $range = new CalendarRange(new \DateTimeImmutable('2026-08-31'), 14);
        $days = [];
        for ($i = 0; $i < $range->days; $i++) {
            $days[] = new CalendarDay($range->start->modify(sprintf('+%d days', $i)), $i, 'po', false, false, false);
        }

        $bar = new CalendarBar(1, 'Novák', 'Novák — Web', 'web', false, false, $span);

        return new CalendarView(
            $range,
            $days,
            [new CalendarRow('Vejminek', [[$bar]])],
            [new CalendarRow('Úklid', [], $markers)],
        );
    }
}
