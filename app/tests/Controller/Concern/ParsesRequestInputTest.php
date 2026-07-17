<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller\Concern;

use App\Controller\Concern\ParsesRequestInput;
use PHPUnit\Framework\TestCase;

/** Trait je chráněný, takže ho testujeme přes minimálního hostitele. */
final class ParsesRequestInputHost
{
    use ParsesRequestInput;

    public function parseDate(?string $raw): ?\DateTimeImmutable
    {
        return $this->parseDateOrNull($raw);
    }

    public function parseAmount(?string $raw): ?string
    {
        return $this->parseAmountOrNull($raw);
    }
}

final class ParsesRequestInputTest extends TestCase
{
    private ParsesRequestInputHost $subject;

    protected function setUp(): void
    {
        $this->subject = new ParsesRequestInputHost();
    }

    public function testEmptyDateIsNull(): void
    {
        self::assertNull($this->subject->parseDate(null));
        self::assertNull($this->subject->parseDate(''));
        self::assertNull($this->subject->parseDate('   '));
    }

    public function testInvalidDateIsNull(): void
    {
        self::assertNull($this->subject->parseDate('nesmysl'));
        self::assertNull($this->subject->parseDate('2026-13-45'));
    }

    public function testParsesDateInput(): void
    {
        self::assertSame('2026-08-10', $this->subject->parseDate('2026-08-10')?->format('Y-m-d'));
    }

    /** Termíny akcí chodí z `datetime-local`, takže čas nesmí zapadnout. */
    public function testParsesDatetimeLocalInput(): void
    {
        self::assertSame('2026-08-10 14:30', $this->subject->parseDate('2026-08-10T14:30')?->format('Y-m-d H:i'));
    }

    public function testParsesAmount(): void
    {
        self::assertSame('1234.50', $this->subject->parseAmount('1 234,50'));
        self::assertSame('8500.00', $this->subject->parseAmount('8500'));
    }

    public function testUnreadableAmountIsNull(): void
    {
        self::assertNull($this->subject->parseAmount('osm tisíc'));
        self::assertNull($this->subject->parseAmount(''));
        self::assertNull($this->subject->parseAmount(null));
    }
}
