<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Invoice\BalanceCalculator;
use App\Invoice\BalanceResult;
use App\Invoice\PaymentStatusResolver;
use App\Mail\GuestMessageSender;
use App\Mail\MessageTemplateProvider;
use App\Notification\OwnerNotifier;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationReceiptRepository;
use App\Timeline\ReservationActionExecutor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * closeIfSatisfied uzavírá jen akce, jejichž cíl je splněný, a nikdy nic
 * neodešle — na rozdíl od plného execute().
 */
#[AllowMockObjectsWithoutExpectations]
final class ReservationActionExecutorCloseTest extends TestCase
{
    private InvoiceRepository&MockObject $invoices;
    private BalanceCalculator&MockObject $balance;
    private GuestMessageSender&MockObject $sender;
    private ReservationActionExecutor $executor;

    protected function setUp(): void
    {
        $this->invoices = $this->createMock(InvoiceRepository::class);
        $this->balance = $this->createMock(BalanceCalculator::class);
        $this->sender = $this->createMock(GuestMessageSender::class);

        $this->executor = new ReservationActionExecutor(
            $this->invoices,
            $this->balance,
            $this->sender,
            $this->createMock(MessageTemplateProvider::class),
            $this->createMock(OwnerNotifier::class),
            // closeIfSatisfied stav platby neřeší; final službu stačí reálná instance.
            new PaymentStatusResolver($this->invoices, $this->createMock(ReservationReceiptRepository::class)),
        );
    }

    public function testIssueFinalClosesWhenInvoiceExists(): void
    {
        $this->invoices->method('findFirstByReservationAndType')->willReturn($this->createMock(Invoice::class));
        $action = $this->action(ActionType::ISSUE_FINAL_INVOICE);

        self::assertTrue($this->executor->closeIfSatisfied($action));
        self::assertSame(ActionStatus::DONE, $action->getStatus());
    }

    public function testIssueFinalStaysOpenWithoutInvoice(): void
    {
        $this->invoices->method('findFirstByReservationAndType')->willReturn(null);
        $action = $this->action(ActionType::ISSUE_FINAL_INVOICE);

        self::assertFalse($this->executor->closeIfSatisfied($action));
        self::assertSame(ActionStatus::PLANNED, $action->getStatus());
    }

    public function testBalanceReminderClosesWhenSettled(): void
    {
        $this->balance->method('forReservation')->willReturn(new BalanceResult(1000.0, 1000.0, 0.0));
        $action = $this->action(ActionType::BALANCE_REMINDER);

        self::assertTrue($this->executor->closeIfSatisfied($action));
        self::assertSame(ActionStatus::DONE, $action->getStatus());
    }

    public function testBalanceReminderStaysOpenAndNeverSendsWhenUnsettled(): void
    {
        $this->balance->method('forReservation')->willReturn(new BalanceResult(1000.0, 400.0, 600.0));
        // Klíčový rozdíl proti execute(): nezaplacený doplatek se NEpřipomíná.
        $this->sender->expects(self::never())->method('canSend');
        $action = $this->action(ActionType::BALANCE_REMINDER);

        self::assertFalse($this->executor->closeIfSatisfied($action));
        self::assertSame(ActionStatus::PLANNED, $action->getStatus());
    }

    public function testUnrelatedActionTypeIsIgnored(): void
    {
        $action = $this->action(ActionType::PRE_ARRIVAL_MESSAGE);

        self::assertFalse($this->executor->closeIfSatisfied($action));
        self::assertSame(ActionStatus::PLANNED, $action->getStatus());
    }

    private function action(ActionType $type): ReservationAction
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('+5 days'));

        return new ReservationAction($reservation, $type, new \DateTimeImmutable('+3 days'));
    }
}
