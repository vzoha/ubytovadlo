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
final class InvoiceServicePaymentMethodTest extends TestCase
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

        $spayd = $this->createMock(SpaydGenerator::class);
        $spayd->method('generate')->willReturn('SPD*1.0*ACC:CZ00*AM:100.00*CC:CZK');

        // Dodavatel (bankovní spojení) z DB — jen to, co test potřebuje.
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                'invoice.bank.account' => '123/0300',
                'invoice.bank.iban' => 'CZ00',
                default => null,
            },
        );
        $issuerProvider = new IssuerProfileProvider($settings, new TaxProfileConfig($settings));

        $this->service = new InvoiceService(
            $em,
            $invoiceRepo,
            $allocator,
            $numberFormat,
            $pdfRenderer,
            $spayd,
            $this->createMock(CnbExchangeRateClient::class),
            $issuerProvider,
            $this->createMock(IncomeUpserter::class),
            new DepositConfig($settings),
            $this->createMock(EventDispatcherInterface::class),
            new Clock(),
        );
    }

    public function testSwitchToCashClearsBankAccountAndQr(): void
    {
        $invoice = $this->service->issueFull($this->webReservation(), new \DateTimeImmutable('2026-05-29'));
        self::assertSame(InvoiceService::PAYMENT_BANK, $invoice->getPaymentMethod());
        self::assertNotNull($invoice->getBankAccount());
        self::assertNotNull($invoice->getQrPayload());

        $this->service->changePaymentMethod($invoice, InvoiceService::PAYMENT_CASH);

        self::assertSame(InvoiceService::PAYMENT_CASH, $invoice->getPaymentMethod());
        self::assertNull($invoice->getBankAccount());
        self::assertNull($invoice->getQrPayload());
    }

    public function testSwitchBackToBankRestoresBankAccountAndQr(): void
    {
        $invoice = $this->service->issueFull($this->webReservation(), new \DateTimeImmutable('2026-05-29'));
        $this->service->changePaymentMethod($invoice, InvoiceService::PAYMENT_CASH);

        $this->service->changePaymentMethod($invoice, InvoiceService::PAYMENT_BANK);

        self::assertSame(InvoiceService::PAYMENT_BANK, $invoice->getPaymentMethod());
        self::assertSame('123/0300', $invoice->getBankAccount());
        self::assertNotNull($invoice->getQrPayload());
    }

    public function testUnknownMethodIsRejected(): void
    {
        $invoice = $this->service->issueFull($this->webReservation(), new \DateTimeImmutable('2026-05-29'));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->changePaymentMethod($invoice, 'kreditka');
    }

    private function webReservation(): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-05-28'));
        $r->setCheckOut(new \DateTimeImmutable('2026-05-30'));
        $r->setGuestName('Jan Novák');
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setBillingMode(BillingMode::ADMIN_BOOKING);
        $r->setPriceTotal('3500.00');
        $r->setPriceCurrency('CZK');

        return $r;
    }
}
