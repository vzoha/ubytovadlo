<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Cashflow\IncomeUpserter;
use App\Entity\Embeddable\Address;
use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\PaymentMethod;
use App\Enum\ReservationStatus;
use App\Invoice\DepositConfig;
use App\Invoice\InvoiceNumber;
use App\Invoice\InvoiceNumberAllocator;
use App\Invoice\InvoiceNumberFormat;
use App\Invoice\InvoicePdfRenderer;
use App\Invoice\InvoiceService;
use App\Invoice\IssuerProfileProvider;
use App\Invoice\SpaydGenerator;
use App\Invoice\TaxProfileConfig;
use App\Repository\InvoiceRepository;
use App\Repository\SettingRepository;
use App\Vat\CnbExchangeRateClient;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Clock\Clock;

#[AllowMockObjectsWithoutExpectations]
final class InvoiceServicePayoutTest extends TestCase
{
    private InvoiceService $service;

    protected function setUp(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $invoiceRepo = $this->createMock(InvoiceRepository::class);
        $invoiceRepo->method('findFirstByReservationAndType')->willReturn(null);

        $allocator = $this->createMock(InvoiceNumberAllocator::class);
        $allocator->method('allocate')->willReturn(new InvoiceNumber(2026, 12));

        $numberFormat = new InvoiceNumberFormat($this->createMock(SettingRepository::class));

        $pdfRenderer = $this->createMock(InvoicePdfRenderer::class);
        $pdfRenderer->method('renderToFile')->willReturn('/tmp/test-invoice.pdf');

        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturn(null);

        $this->service = new InvoiceService(
            $em,
            $invoiceRepo,
            $allocator,
            $numberFormat,
            $pdfRenderer,
            $this->createMock(SpaydGenerator::class),
            $this->createMock(CnbExchangeRateClient::class),
            new IssuerProfileProvider($settings, new TaxProfileConfig($settings)),
            $this->createMock(IncomeUpserter::class),
            new DepositConfig($settings),
            $this->createMock(EventDispatcherInterface::class),
            new Clock(),
        );
    }

    /**
     * Host platí portálu při rezervaci, tedy dřív než doklad vznikne — faktura
     * je uhrazená dnem vystavení a splatnost na ní nemá co dělat.
     */
    public function testOtaInvoiceIsIssuedSettledAndWithoutDueDate(): void
    {
        $invoice = $this->service->issueFull($this->airbnbReservation(), new \DateTimeImmutable('2026-05-29'));

        self::assertSame(PaymentMethod::PREPAID_INTERMEDIARY, $invoice->getPaymentMethod());
        self::assertTrue($invoice->isPaid());
        self::assertSame('2026-05-29', $invoice->getPaidAt()?->format('Y-m-d'));
        self::assertNull($invoice->getDueAt());
        self::assertNull($invoice->getBankAccount());
    }

    /** Výplata od portálu je pohyb peněz na rezervaci, do dokladu nevstupuje. */
    public function testPayoutDateDoesNotBecomeInvoicePaymentDate(): void
    {
        $reservation = $this->airbnbReservation();
        $reservation->setPayoutSentAt(new \DateTimeImmutable('2026-06-02'));

        $invoice = $this->service->issueFull($reservation, new \DateTimeImmutable('2026-05-29'));

        self::assertSame('2026-05-29', $invoice->getPaidAt()?->format('Y-m-d'));
    }

    /** Booking vybírá platbu stejně jako Airbnb — doklad vzniká uhrazený. */
    public function testBookingInvoiceIsIssuedSettled(): void
    {
        $reservation = $this->otaReservation(Channel::BOOKING, BillingMode::BOOKING_COM);

        $invoice = $this->service->issueFull($reservation, new \DateTimeImmutable('2026-05-29'));

        self::assertSame(PaymentMethod::PREPAID_INTERMEDIARY, $invoice->getPaymentMethod());
        self::assertTrue($invoice->isPaid());
        self::assertNull($invoice->getDueAt());
    }

    private function airbnbReservation(): Reservation
    {
        return $this->otaReservation(Channel::AIRBNB, BillingMode::AIRBNB);
    }

    private function otaReservation(Channel $channel, BillingMode $mode): Reservation
    {
        $r = new Reservation($channel, new \DateTimeImmutable('2026-05-28'));
        $r->setCheckOut(new \DateTimeImmutable('2026-05-30'));
        $r->setGuestName('Eva Marková');
        $r->setGuestAddress(new Address('Nějaká 1', 'Praha', '11000'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setBillingMode($mode);
        $r->setPriceTotal('3298.00');
        $r->setPriceCurrency('CZK');

        return $r;
    }
}
