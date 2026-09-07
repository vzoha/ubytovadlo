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
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Enum\PaymentMethod;
use App\Invoice\PaymentMethodLabeler;
use PHPUnit\Framework\TestCase;

final class PaymentMethodLabelerTest extends TestCase
{
    /** Host má na dokladu poznat, které platbě odpovídá — proto jméno portálu. */
    public function testPrepaidInvoiceNamesThePortal(): void
    {
        $label = (new PaymentMethodLabeler())->label($this->invoice(Channel::AIRBNB, PaymentMethod::PREPAID_INTERMEDIARY));

        self::assertSame('přes portál Airbnb', $label);
    }

    /** Mimo portál se jméno brát odkud — zůstane obecné znění. */
    public function testPrepaidInvoiceOutsidePortalStaysGeneric(): void
    {
        $label = (new PaymentMethodLabeler())->label($this->invoice(Channel::WEB, PaymentMethod::PREPAID_INTERMEDIARY));

        self::assertSame('přes zprostředkovatele', $label);
    }

    public function testOrdinaryPaymentKeepsItsOwnLabel(): void
    {
        $label = (new PaymentMethodLabeler())->label($this->invoice(Channel::WEB, PaymentMethod::CASH));

        self::assertSame('hotově', $label);
    }

    private function invoice(Channel $channel, PaymentMethod $method): Invoice
    {
        $reservation = new Reservation($channel, new \DateTimeImmutable('2026-09-07'));
        $reservation->setGuestName('Jan Novák');

        $invoice = new Invoice(
            '2026001',
            2026,
            1,
            InvoiceType::FULL,
            $reservation,
            new \DateTimeImmutable('2026-09-07'),
            null,
        );

        return $invoice->setPaymentMethod($method);
    }
}
