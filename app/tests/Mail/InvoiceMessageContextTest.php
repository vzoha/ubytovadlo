<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Mail\GuestLocaleResolver;
use App\Mail\InvoiceMessageContext;
use App\Repository\InvoiceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InvoiceMessageContextTest extends TestCase
{
    public function testUnpaidInvoiceCarriesAmountDueDateAndAccount(): void
    {
        $invoice = $this->invoice();
        $invoice->setDueAt(new \DateTimeImmutable('2026-09-20'));
        $invoice->setBankAccount('1861547133/0800');

        $values = $this->context()->forInvoice($invoice);

        self::assertSame("4\u{00a0}200,00\u{00a0}Kč", $values['invoice_total']);
        self::assertSame("k úhradě do 20.\u{00a0}9.\u{00a0}2026", $values['invoice_payment_status']);
        self::assertSame("20.\u{00a0}9.\u{00a0}2026", $values['invoice_due']);
        self::assertSame('1861547133/0800', $values['invoice_bank_account']);
        self::assertSame('2026012', $values['invoice_variable_symbol']);
    }

    public function testPaidInvoiceSaysSoAndDropsDueDateAndQr(): void
    {
        $invoice = $this->invoice();
        $invoice->setDueAt(new \DateTimeImmutable('2026-09-20'));
        $invoice->setPaidAt(new \DateTimeImmutable('2026-09-07'));
        $invoice->setQrPayload('SPD*1.0*ACC:CZ65');

        $values = $this->context()->forInvoice($invoice);

        self::assertSame("uhrazeno 7.\u{00a0}9.\u{00a0}2026", $values['invoice_payment_status']);
        self::assertSame('', $values['invoice_due']);
        self::assertSame('', $values['invoice_qr']);
    }

    public function testInvoiceWithoutDueDateSaysOnlyThatItIsUnpaid(): void
    {
        $values = $this->context()->forInvoice($this->invoice());

        self::assertSame('k úhradě', $values['invoice_payment_status']);
        self::assertSame('', $values['invoice_due']);
    }

    /** Host mimo Česko a Slovensko dostane zprávu anglicky — stav úhrady taky. */
    public function testPaymentStatusFollowsGuestLanguage(): void
    {
        $invoice = $this->invoice();
        $reservation = $invoice->getReservation();
        $reservation->setGuestAddress($reservation->getGuestAddress()->withCountry('DE'));
        $invoice->setDueAt(new \DateTimeImmutable('2026-09-20'));

        $values = $this->context()->forInvoice($invoice);

        self::assertSame("due by 20\u{00a0}Sep\u{00a0}2026", $values['invoice_payment_status']);
    }

    /** Neuložená faktura (náhled) QR obrázek nemá — endpoint by ji nenašel. */
    public function testQrEmptyForUnsavedInvoice(): void
    {
        $invoice = $this->invoice();
        $invoice->setQrPayload('SPD*1.0*ACC:CZ65');

        self::assertSame('', $this->context()->forInvoice($invoice)['invoice_qr']);
    }

    /** Zpráva bez přílohy (připomínka doplatku) si fakturu najde podle rezervace. */
    public function testForReservationFindsFinalInvoice(): void
    {
        $invoice = $this->invoice();
        $invoice->setBankAccount('1861547133/0800');
        $repo = $this->createStub(InvoiceRepository::class);
        $repo->method('findFirstByReservationAndType')->willReturn($invoice);

        $values = $this->context($repo)->forReservation($this->persisted($invoice->getReservation()));

        self::assertSame('2026012', $values['invoice_number']);
        self::assertSame('1861547133/0800', $values['invoice_bank_account']);
    }

    public function testForReservationWithoutInvoiceIsEmpty(): void
    {
        $repo = $this->createStub(InvoiceRepository::class);
        $repo->method('findFirstByReservationAndType')->willReturn(null);

        self::assertSame([], $this->context($repo)->forReservation($this->persisted($this->invoice()->getReservation())));
    }

    /** Neuložená rezervace (náhled) se v databázi nehledá. */
    public function testForUnsavedReservationIsEmpty(): void
    {
        self::assertSame([], $this->context()->forReservation($this->invoice()->getReservation()));
    }

    /** Doctrine identitu rezervace čte přes ID — v testu ho doplníme reflexí. */
    private function persisted(Reservation $reservation): Reservation
    {
        $id = new \ReflectionProperty(Reservation::class, 'id');
        $id->setValue($reservation, 42);

        return $reservation;
    }

    private function context(?InvoiceRepository $invoices = null): InvoiceMessageContext
    {
        $url = $this->createStub(UrlGeneratorInterface::class);
        $url->method('generate')->willReturn('https://app.example.com/qr/faktura/token/1.png');

        return new InvoiceMessageContext($url, new GuestLocaleResolver(), $invoices ?? $this->createStub(InvoiceRepository::class));
    }

    private function invoice(): Invoice
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-09-19'));
        $reservation->setCheckOut(new \DateTimeImmutable('2026-09-22'));
        $reservation->setGuestName('Jan Novák');

        $invoice = new Invoice(
            '2026012',
            2026,
            12,
            InvoiceType::FINAL,
            $reservation,
            new \DateTimeImmutable('2026-09-13'),
            null,
        );
        $invoice->setTotalAmount('4200');

        return $invoice;
    }
}
