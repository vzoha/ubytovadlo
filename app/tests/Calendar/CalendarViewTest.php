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
use App\Calendar\CalendarMarker;
use App\Calendar\CalendarRange;
use App\Calendar\CalendarRow;
use App\Calendar\CalendarView;
use App\Enum\Channel;
use PHPUnit\Framework\TestCase;

final class CalendarViewTest extends TestCase
{
    public function testLegendListsOnlyChannelsThatAreOnScreen(): void
    {
        $view = $this->view([$this->bar(Channel::AIRBNB), $this->bar(Channel::WEB), $this->bar(Channel::AIRBNB)]);

        // Pořadí drží enum, ne pořadí rezervací — legenda se mezi měsíci nepřehazuje.
        self::assertSame([Channel::WEB, Channel::AIRBNB], $view->channels());
    }

    public function testMonthWithoutStaysHasNoLegend(): void
    {
        self::assertSame([], $this->view([])->channels());
    }

    public function testUnconfirmedIsReportedOnlyWhenSuchStayExists(): void
    {
        self::assertFalse($this->view([$this->bar(Channel::WEB)])->hasUnconfirmed());
        self::assertTrue($this->view([$this->bar(Channel::BOOKING, true)])->hasUnconfirmed());
    }

    public function testViewWithOnlyServiceMarkersIsNotEmpty(): void
    {
        $view = new CalendarView(
            new CalendarRange(new \DateTimeImmutable('2026-09-01'), 30),
            [],
            [new CalendarRow('Vejminek')],
            [new CalendarRow('Termíny', [], [new CalendarMarker(3, '⚠', 'Revize', 'danger')])],
        );

        self::assertFalse($view->isEmpty());
        self::assertSame([], $view->channels());
    }

    private function bar(Channel $channel, bool $unconfirmed = false): CalendarBar
    {
        return new CalendarBar(1, 'Host', 'Host — pobyt', $channel, $unconfirmed, false, new BarSpan(0.5, 3.5, false, false));
    }

    /** @param list<CalendarBar> $bars */
    private function view(array $bars): CalendarView
    {
        return new CalendarView(
            new CalendarRange(new \DateTimeImmutable('2026-09-01'), 30),
            [],
            [new CalendarRow('Vejminek', $bars === [] ? [] : [$bars])],
            [],
        );
    }
}
