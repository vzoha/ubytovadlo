<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Email\Dto\CsPaymentData;
use App\Email\EmailMessage;
use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Invoice\DepositConfig;
use App\Invoice\InvoiceService;
use App\Mail\ConfirmationResult;
use App\Mail\ReservationConfirmation;
use App\Payment\Event\PaymentSettledEvent;
use App\Payment\PaymentProcessor;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Repository\SettingRepository;
use App\Timeline\ReservationActionPlanner;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

#[AllowMockObjectsWithoutExpectations]
final class PaymentProcessorTest extends TestCase
{
    private EntityManagerInterface&MockObject $em;
    private InvoiceRepository&MockObject $invoices;
    private ReservationRepository&MockObject $reservations;
    private InvoiceService&MockObject $invoiceService;
    private ReservationActionPlanner&MockObject $planner;
    private EventDispatcherInterface&MockObject $dispatcher;
    private ReservationConfirmation&MockObject $confirmation;
    private PaymentProcessor $processor;
    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->invoices = $this->createMock(InvoiceRepository::class);
        $this->reservations = $this->createMock(ReservationRepository::class);
        $this->invoiceService = $this->createMock(InvoiceService::class);
        $this->planner = $this->createMock(ReservationActionPlanner::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->confirmation = $this->createMock(ReservationConfirmation::class);
        $this->confirmation->method('confirm')->willReturn(new ConfirmationResult(true, true, null));

        $this->persisted = [];
        $this->em->method('persist')->willReturnCallback(function (object $e): void {
            $this->persisted[] = $e;
        });

        // Fixní záloha 1000 Kč nastavená v DB (režim „fixní" je default).
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturnCallback(
            static fn (string $key): ?string => $key === DepositConfig::KEY_VALUE ? '1000' : null,
        );

        $this->processor = new PaymentProcessor(
            $this->em,
            $this->invoices,
            $this->reservations,
            $this->invoiceService,
            $this->planner,
            $this->dispatcher,
            new DepositConfig($settings),
            $this->confirmation,
        );
    }

    public function testIssuesAndPaysDepositWhenVsMatchesReservation(): void
    {
        $reservation = $this->reservation(BillingMode::STANDARD_WITH_DEPOSIT, 'Jan Novák');

        $this->invoices->method('findOneByVariableSymbol')->willReturn(null);
        $this->reservations->method('findByPaymentVariableSymbol')->willReturn($reservation);
        $this->invoices->method('findFirstByReservationAndType')->willReturn(null);

        $deposit = $this->createMock(Invoice::class);
        $deposit->method('isPaid')->willReturn(false);
        $deposit->method('getCurrency')->willReturn('CZK');
        $this->invoiceService->expects(self::once())
            ->method('issueDeposit')
            ->with($reservation, self::callback(static fn (\DateTimeImmutable $d) => $d->format('Y-m-d') === '2026-06-16'))
            ->willReturn($deposit);
        $this->invoiceService->expects(self::once())->method('markPaid')->with($deposit);
        $this->planner->expects(self::once())->method('planFor')->with($reservation);
        $this->dispatcher->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(PaymentSettledEvent::class));

        $result = $this->processor->process($this->deposit('1753'), $this->email());

        self::assertSame($reservation, $result->reservation);
        $payment = $this->persistedPayment();
        self::assertSame($reservation, $payment->getReservation());
        self::assertSame($deposit, $payment->getInvoice());
    }

    public function testMarksInvoicePaidWhenVsMatchesInvoiceNumber(): void
    {
        $reservation = $this->reservation(BillingMode::STANDARD_WITH_DEPOSIT, 'Jan Novák');
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('isPaid')->willReturn(false);
        $invoice->method('getCurrency')->willReturn('CZK');
        $invoice->method('getReservation')->willReturn($reservation);

        $this->invoices->method('findOneByVariableSymbol')->willReturn($invoice);
        $this->invoiceService->expects(self::once())->method('markPaid')->with($invoice);
        $this->invoiceService->expects(self::never())->method('issueDeposit');
        $this->planner->expects(self::once())->method('planFor')->with($reservation);

        $result = $this->processor->process($this->deposit('2026012'), $this->email());

        self::assertSame($reservation, $result->reservation);
        self::assertSame($invoice, $this->persistedPayment()->getInvoice());
    }

    public function testDoesNotSettleInvoiceOnCurrencyMismatch(): void
    {
        $reservation = $this->reservation(BillingMode::STANDARD_WITH_DEPOSIT, 'Jan Novák');
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('isPaid')->willReturn(false);
        $invoice->method('getCurrency')->willReturn('CZK');
        $invoice->method('getReservation')->willReturn($reservation);

        $this->invoices->method('findOneByVariableSymbol')->willReturn($invoice);
        $this->invoiceService->expects(self::never())->method('markPaid');

        $data = new CsPaymentData(true, '1000.00', 'EUR', '2026012', '0', null, new \DateTimeImmutable('2026-06-16'));
        $result = $this->processor->process($data, $this->email());

        self::assertSame($reservation, $result->reservation);
        self::assertSame($invoice, $this->persistedPayment()->getInvoice());
    }

    public function testRecordsUnmatchedPaymentWhenNoReservationFound(): void
    {
        $this->invoices->method('findOneByVariableSymbol')->willReturn(null);
        $this->reservations->method('findByPaymentVariableSymbol')->willReturn(null);
        $this->invoiceService->expects(self::never())->method('markPaid');
        $this->invoiceService->expects(self::never())->method('issueDeposit');
        $this->dispatcher->expects(self::never())->method('dispatch');

        $result = $this->processor->process($this->deposit('9999'), $this->email());

        self::assertNull($result->reservation);
        self::assertStringContainsString('9999', (string) $result->ignoredReason);
        self::assertNull($this->persistedPayment()->getReservation());
    }

    public function testDoesNotIssueDepositOnAmountMismatch(): void
    {
        $reservation = $this->reservation(BillingMode::STANDARD_WITH_DEPOSIT, 'Jan Novák');
        $this->invoices->method('findOneByVariableSymbol')->willReturn(null);
        $this->reservations->method('findByPaymentVariableSymbol')->willReturn($reservation);
        $this->invoiceService->expects(self::never())->method('issueDeposit');
        $this->invoiceService->expects(self::never())->method('markPaid');

        $data = new CsPaymentData(true, '7500.00', 'CZK', '1753', '0', '987654321/0100', new \DateTimeImmutable('2026-06-16'));
        $result = $this->processor->process($data, $this->email());

        self::assertSame($reservation, $result->reservation);
    }

    public function testDoesNotIssueDepositWhenGuestDetailsMissing(): void
    {
        $reservation = $this->reservation(BillingMode::STANDARD_WITH_DEPOSIT, null);
        $this->invoices->method('findOneByVariableSymbol')->willReturn(null);
        $this->reservations->method('findByPaymentVariableSymbol')->willReturn($reservation);
        $this->invoices->method('findFirstByReservationAndType')->willReturn(null);
        $this->invoiceService->expects(self::never())->method('issueDeposit');
        $this->invoiceService->expects(self::never())->method('markPaid');

        $result = $this->processor->process($this->deposit('1753'), $this->email());

        self::assertSame($reservation, $result->reservation);
    }

    public function testIgnoresOutgoingPaymentWithoutPersisting(): void
    {
        $this->em->expects(self::never())->method('persist');

        $data = new CsPaymentData(false, '1000.00', 'CZK', '1753', '0', null, new \DateTimeImmutable('2026-06-16'));
        $result = $this->processor->process($data, $this->email());

        self::assertNull($result->reservation);
        self::assertStringContainsString('Odchozí', (string) $result->ignoredReason);
    }

    private function reservation(BillingMode $mode, ?string $guestName): Reservation
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-07-10'));
        $reservation->setBillingMode($mode);
        if ($guestName !== null) {
            $reservation->setGuestName($guestName);
        }

        return $reservation;
    }

    private function deposit(string $vs): CsPaymentData
    {
        return new CsPaymentData(true, '1000.00', 'CZK', $vs, '0', '987654321/0100', new \DateTimeImmutable('2026-06-16'));
    }

    private function email(): EmailMessage
    {
        return new EmailMessage(
            messageId: '<prisla-platba@csas.cz>',
            fromAddress: 'ceskasporitelna@csas.cz',
            subject: 'Přišla platba',
            date: new \DateTimeImmutable('2026-06-16 13:36:06'),
            textBody: '',
        );
    }

    private function persistedPayment(): Payment
    {
        foreach ($this->persisted as $entity) {
            if ($entity instanceof Payment) {
                return $entity;
            }
        }
        self::fail('Žádná platba nebyla persistována.');
    }
}
