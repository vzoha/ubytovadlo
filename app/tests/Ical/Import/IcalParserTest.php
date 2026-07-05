<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Ical\Import;

use App\Ical\Import\IcalParser;
use PHPUnit\Framework\TestCase;

final class IcalParserTest extends TestCase
{
    private IcalParser $parser;

    protected function setUp(): void
    {
        $this->parser = new IcalParser();
    }

    private function wrap(string $body): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\n{$body}\r\nEND:VCALENDAR\r\n";
    }

    public function testParsesBasicDateEvent(): void
    {
        $events = $this->parser->parse($this->wrap(
            "BEGIN:VEVENT\r\nUID:abc@airbnb.com\r\nDTSTART;VALUE=DATE:20260810\r\nDTEND;VALUE=DATE:20260815\r\nSUMMARY:Reserved\r\nEND:VEVENT"
        ));

        self::assertCount(1, $events);
        self::assertSame('abc@airbnb.com', $events[0]->uid);
        self::assertSame('2026-08-10', $events[0]->start->format('Y-m-d'));
        self::assertSame('2026-08-15', $events[0]->end?->format('Y-m-d'));
        self::assertSame('Reserved', $events[0]->summary);
        self::assertFalse($events[0]->cancelled);
    }

    public function testParsesDatetimeForm(): void
    {
        $events = $this->parser->parse($this->wrap(
            "BEGIN:VEVENT\r\nUID:x\r\nDTSTART:20260810T140000Z\r\nDTEND:20260815T100000Z\r\nEND:VEVENT"
        ));

        self::assertSame('2026-08-10', $events[0]->start->format('Y-m-d'));
        self::assertSame('2026-08-15', $events[0]->end?->format('Y-m-d'));
    }

    public function testUnfoldsContinuationLines(): void
    {
        $events = $this->parser->parse($this->wrap(
            "BEGIN:VEVENT\r\nUID:x\r\nDTSTART;VALUE=DATE:20260810\r\nSUMMARY:Very long summ\r\n ary continued\r\nEND:VEVENT"
        ));

        self::assertSame('Very long summary continued', $events[0]->summary);
    }

    public function testSkipsTransparentEvents(): void
    {
        $events = $this->parser->parse($this->wrap(
            "BEGIN:VEVENT\r\nUID:x\r\nDTSTART;VALUE=DATE:20260810\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT"
        ));

        self::assertCount(0, $events);
    }

    public function testMarksCancelledStatus(): void
    {
        $events = $this->parser->parse($this->wrap(
            "BEGIN:VEVENT\r\nUID:x\r\nDTSTART;VALUE=DATE:20260810\r\nSTATUS:CANCELLED\r\nEND:VEVENT"
        ));

        self::assertTrue($events[0]->cancelled);
    }

    public function testSkipsEventWithoutUidOrStart(): void
    {
        $events = $this->parser->parse($this->wrap(
            "BEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20260810\r\nEND:VEVENT"
                . "\r\nBEGIN:VEVENT\r\nUID:only-uid\r\nEND:VEVENT"
        ));

        self::assertCount(0, $events);
    }

    public function testParsesMultipleEvents(): void
    {
        $events = $this->parser->parse($this->wrap(
            "BEGIN:VEVENT\r\nUID:a\r\nDTSTART;VALUE=DATE:20260810\r\nEND:VEVENT"
                . "\r\nBEGIN:VEVENT\r\nUID:b\r\nDTSTART;VALUE=DATE:20260901\r\nEND:VEVENT"
        ));

        self::assertCount(2, $events);
        self::assertSame('a', $events[0]->uid);
        self::assertSame('b', $events[1]->uid);
        self::assertNull($events[1]->end);
    }

    public function testUnescapesSummary(): void
    {
        $events = $this->parser->parse($this->wrap(
            "BEGIN:VEVENT\r\nUID:x\r\nDTSTART;VALUE=DATE:20260810\r\nSUMMARY:Jan\\, Novák\\; pozn\\ndruhý řádek\r\nEND:VEVENT"
        ));

        self::assertSame("Jan, Novák; pozn\ndruhý řádek", $events[0]->summary);
    }

    public function testAcceptsLfOnlyLineEndings(): void
    {
        $events = $this->parser->parse(
            "BEGIN:VCALENDAR\nBEGIN:VEVENT\nUID:x\nDTSTART;VALUE=DATE:20260810\nEND:VEVENT\nEND:VCALENDAR\n"
        );

        self::assertCount(1, $events);
        self::assertSame('x', $events[0]->uid);
    }
}
