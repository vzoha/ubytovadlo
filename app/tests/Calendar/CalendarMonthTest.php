<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Calendar;

use App\Calendar\CalendarMonth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CalendarMonthTest extends TestCase
{
    public function testParsesMonthFromUrl(): void
    {
        $month = CalendarMonth::fromString('2026-09', new \DateTimeImmutable('2026-01-15'));

        self::assertSame(2026, $month->year);
        self::assertSame(9, $month->month);
        self::assertSame('září 2026', $month->label());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function invalidMonths(): iterable
    {
        yield 'prázdné' => [null];
        yield 'nesmysl' => ['pondeli'];
        yield 'měsíc mimo rozsah' => ['2026-13'];
        yield 'rok mimo rozsah' => ['1899-05'];
        yield 'pokus o injection' => ['2026-09; DROP'];
    }

    #[DataProvider('invalidMonths')]
    public function testFallsBackToCurrentMonth(?string $value): void
    {
        $month = CalendarMonth::fromString($value, new \DateTimeImmutable('2026-01-15'));

        self::assertSame('2026-01', $month->value());
    }

    public function testTimelineRangeCoversExactlyTheMonth(): void
    {
        $range = (new CalendarMonth(2026, 2))->timelineRange();

        self::assertSame('2026-02-01', $range->start->format('Y-m-d'));
        self::assertSame(28, $range->days);
        self::assertSame('2026-03-01', $range->endExclusive()->format('Y-m-d'));
    }

    public function testGridRangeStartsOnMondayAndHoldsWholeWeeks(): void
    {
        // Září 2026 začíná v úterý a končí ve středu — mřížka přetéká na obě strany.
        $range = (new CalendarMonth(2026, 9))->gridRange();

        self::assertSame('2026-08-31', $range->start->format('Y-m-d'));
        self::assertSame(0, $range->days % 7);
        self::assertSame('2026-10-05', $range->endExclusive()->format('Y-m-d'));
    }

    public function testNeighbouringMonthsCrossYear(): void
    {
        $january = new CalendarMonth(2026, 1);

        self::assertSame('2025-12', $january->previous()->value());
        self::assertSame('2026-02', $january->next()->value());
    }
}
