<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Ical;

use App\Entity\Reservation;
use App\Enum\Channel;
use App\Ical\ICalendarWriter;
use PHPUnit\Framework\TestCase;

final class ICalendarWriterTest extends TestCase
{
    private function reservation(int $id, string $in, ?string $out): Reservation
    {
        $r = new Reservation(Channel::DIRECT, new \DateTimeImmutable($in));
        if ($out !== null) {
            $r->setCheckOut(new \DateTimeImmutable($out));
        }
        $ref = new \ReflectionProperty(Reservation::class, 'id');
        $ref->setValue($r, $id);

        return $r;
    }

    public function testWrapsEventsInCalendar(): void
    {
        $ics = (new ICalendarWriter())->build([$this->reservation(1, '2026-08-10', '2026-08-14')], 'example.com');

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("VERSION:2.0\r\n", $ics);
        self::assertStringContainsString("BEGIN:VEVENT\r\n", $ics);
        // CRLF všude, žádné osamocené LF.
        self::assertSame(substr_count($ics, "\n"), substr_count($ics, "\r\n"));
    }

    public function testEventCarriesDatesUidAndNeutralSummary(): void
    {
        $ics = (new ICalendarWriter())->build([$this->reservation(42, '2026-08-10', '2026-08-14')], 'example.com');

        self::assertStringContainsString('UID:reservation-42@example.com', $ics);
        self::assertStringContainsString('DTSTART;VALUE=DATE:20260810', $ics);
        self::assertStringContainsString('DTEND;VALUE=DATE:20260814', $ics);
        self::assertStringContainsString('SUMMARY:Obsazeno', $ics);
        // Bez PII: jméno hosta se do feedu nedostane.
        self::assertStringNotContainsString('DIRECT', $ics);
    }

    public function testMissingCheckoutBlocksOneNight(): void
    {
        $ics = (new ICalendarWriter())->build([$this->reservation(7, '2026-09-01', null)], 'example.com');

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260901', $ics);
        self::assertStringContainsString('DTEND;VALUE=DATE:20260902', $ics);
    }

    public function testEmptyListStillValidCalendar(): void
    {
        $ics = (new ICalendarWriter())->build([], 'example.com');

        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringContainsString("END:VCALENDAR\r\n", $ics);
        self::assertStringNotContainsString('VEVENT', $ics);
    }
}
