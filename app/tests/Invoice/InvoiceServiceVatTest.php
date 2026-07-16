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
use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Enum\TaxProfile;
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
final class InvoiceServiceVatTest extends TestCase
{
    public function testVatPayerFullInvoiceCarriesOutputVat(): void
    {
        // Brutto 11 200 při 12 % → základ 10 000, DPH 1 200 (daň shora).
        $invoice = $this->service('vat_payer')->issueFull($this->webReservation('11200.00'), new \DateTimeImmutable('2026-05-29'));

        self::assertSame(TaxProfile::VAT_PAYER, $invoice->getTaxProfileSnapshot());
        self::assertTrue($invoice->hasOutputVat());
        self::assertSame('10000.00', $invoice->getVatBaseTotal());
        self::assertSame('1200.00', $invoice->getVatAmountTotal());
        // Celková částka zůstává brutto — QR i úhrada beze změny.
        self::assertSame('11200.00', $invoice->getTotalAmount());
        self::assertSame('12.00', $invoice->getLines()->first()->getVatRate());
    }

    public function testIdentifiedPersonInvoiceHasNoOutputVat(): void
    {
        $invoice = $this->service('identified_person')->issueFull($this->webReservation('11200.00'), new \DateTimeImmutable('2026-05-29'));

        self::assertSame(TaxProfile::IDENTIFIED_PERSON, $invoice->getTaxProfileSnapshot());
        self::assertFalse($invoice->hasOutputVat());
        self::assertNull($invoice->getVatBaseTotal());
        self::assertNull($invoice->getVatAmountTotal());
        self::assertNull($invoice->getLines()->first()->getVatRate());
    }

    private function service(string $taxProfile): InvoiceService
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
        $settings->method('getString')->willReturnCallback(
            static fn (string $key): ?string => $key === TaxProfileConfig::KEY ? $taxProfile : null,
        );

        return new InvoiceService(
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

    private function webReservation(string $priceTotal): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-05-28'));
        $r->setCheckOut(new \DateTimeImmutable('2026-05-30'));
        $r->setGuestName('Jan Novák');
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setBillingMode(BillingMode::ADMIN_BOOKING);
        $r->setPriceTotal($priceTotal);
        $r->setPriceCurrency('CZK');

        return $r;
    }
}
