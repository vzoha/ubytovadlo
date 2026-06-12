<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Vat;

use App\Vat\BookingInvoiceParser;
use PHPUnit\Framework\TestCase;

final class BookingInvoiceParserTest extends TestCase
{
    public function testParsesApril2026Invoice(): void
    {
        $parser = new BookingInvoiceParser();
        $d = $parser->parseFile(__DIR__ . '/../Fixtures/Booking/invoice-8000000001.pdf');

        self::assertSame('8000000001', $d->invoiceNumber);
        self::assertSame('2026-05-03', $d->issuedAt->format('Y-m-d'));
        self::assertSame('2026-04-01', $d->periodFrom->format('Y-m-d'));
        self::assertSame('2026-04-30', $d->periodTo->format('Y-m-d'));
        self::assertSame('EUR', $d->currency);
        self::assertSame('800.00', $d->roomSales);
        self::assertSame('120.00', $d->commission);
        self::assertSame('13.20', $d->paymentFee);
        self::assertSame('133.20', $d->totalPayable);
        self::assertSame('24.36900127', $d->bookingExchangeRate);
    }

    public function testAmountParserHandlesThousandsSeparator(): void
    {
        $parser = new BookingInvoiceParser();
        $text = "Číslo faktury: 1000000000\n"
            . "Datum: 03/05/2026\n"
            . "Období: 01/04/2026 - 30/04/2026\n"
            . "Rezervace EUR 12 345,67 EUR 1 234,56\n"
            . "K zaplacení celkem EUR 1 234,56\n";

        $d = $parser->parseText($text);

        self::assertSame('12345.67', $d->roomSales);
        self::assertSame('1234.56', $d->commission);
        self::assertSame('1234.56', $d->totalPayable);
        self::assertSame('0.00', $d->paymentFee);
    }
}
