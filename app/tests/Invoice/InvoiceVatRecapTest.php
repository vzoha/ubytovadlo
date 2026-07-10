<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Invoice\InvoiceVatRecap;
use App\Invoice\VatRates;
use PHPUnit\Framework\TestCase;

final class InvoiceVatRecapTest extends TestCase
{
    public function testAccommodationLineSplitsTaxFromTop(): void
    {
        // Brutto 11 200 při 12 % → základ 10 000, DPH 1 200.
        $invoice = $this->invoice();
        $invoice->addLine(new InvoiceLine('Ubytovací služby', '11200.00', vatRate: VatRates::ACCOMMODATION));

        $recap = InvoiceVatRecap::fromInvoice($invoice);

        self::assertTrue($recap->hasVat());
        self::assertCount(1, $recap->rows);
        self::assertSame('10000.00', $recap->baseTotal);
        self::assertSame('1200.00', $recap->vatTotal);
        self::assertSame('11200.00', $recap->grossTotal);
        self::assertSame('12.00', $recap->rows[0]->rate);
    }

    public function testDepositDeductionNetsWithinRate(): void
    {
        // Konečná: +11 200 a −1 120 (odpočet zálohy) v téže sazbě → doplatek 10 080 brutto.
        $invoice = $this->invoice();
        $invoice->addLine(new InvoiceLine('Ubytovací služby', '11200.00', vatRate: VatRates::ACCOMMODATION));
        $invoice->addLine(new InvoiceLine('Odpočet zálohy', '-1120.00', vatRate: VatRates::ACCOMMODATION));

        $recap = InvoiceVatRecap::fromInvoice($invoice);

        self::assertCount(1, $recap->rows);
        self::assertSame('9000.00', $recap->baseTotal);
        self::assertSame('1080.00', $recap->vatTotal);
        self::assertSame('10080.00', $recap->grossTotal);
    }

    public function testLinesWithoutRateHaveNoRecap(): void
    {
        $invoice = $this->invoice();
        $invoice->addLine(new InvoiceLine('Ubytovací služby', '5000.00'));

        $recap = InvoiceVatRecap::fromInvoice($invoice);

        self::assertFalse($recap->hasVat());
        self::assertSame([], $recap->rows);
        self::assertSame('0', $recap->vatTotal);
    }

    private function invoice(): Invoice
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-05-01'));
        $reservation->setGuestName('Jan Novák');

        return new Invoice(
            '2026012',
            2026,
            12,
            InvoiceType::FULL,
            $reservation,
            new \DateTimeImmutable('2026-05-01'),
            new \DateTimeImmutable('2026-05-03'),
        );
    }
}
