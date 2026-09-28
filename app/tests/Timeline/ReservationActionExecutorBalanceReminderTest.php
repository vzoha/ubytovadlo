<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Enum\OwnerNotificationType;
use App\Invoice\BalanceCalculator;
use App\Invoice\BalanceResult;
use App\Invoice\PaymentStatusResolver;
use App\Notification\OwnerNotifier;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationReceiptRepository;
use App\Timeline\GuestMessageDispatcher;
use App\Timeline\ReservationActionExecutor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Připomínka doplatku, která neodejde sama (host bez e-mailu, ruční režim),
 * jednou upozorní ubytovatele, ať ji pošle z okna „Poslat".
 */
#[AllowMockObjectsWithoutExpectations]
final class ReservationActionExecutorBalanceReminderTest extends TestCase
{
    private GuestMessageDispatcher&MockObject $guestMessages;
    private OwnerNotifier&MockObject $notifier;
    private ReservationActionExecutor $executor;

    protected function setUp(): void
    {
        $invoices = $this->createMock(InvoiceRepository::class);
        $balance = $this->createMock(BalanceCalculator::class);
        $balance->method('forReservation')->willReturn(new BalanceResult(1000.0, 400.0, 600.0));
        $this->guestMessages = $this->createMock(GuestMessageDispatcher::class);
        $this->notifier = $this->createMock(OwnerNotifier::class);
        $this->executor = new ReservationActionExecutor(
            $invoices,
            $balance,
            $this->guestMessages,
            $this->notifier,
            new PaymentStatusResolver($invoices, $this->createMock(ReservationReceiptRepository::class)),
        );
    }

    public function testNotifiesOwnerOnceWhenReminderCannotGoOutAlone(): void
    {
        $this->guestMessages->method('remindAboutBalance')->willReturn(false);
        $this->notifier->expects(self::once())
            ->method('notify')
            ->with(OwnerNotificationType::BALANCE_REMINDER_DUE)
            ->willReturn(true);
        $action = $this->action();

        self::assertTrue($this->executor->execute($action));
        self::assertFalse($this->executor->execute($action));
        self::assertSame(ActionStatus::PLANNED, $action->getStatus());
    }

    public function testMailedReminderDoesNotBotherOwner(): void
    {
        $this->guestMessages->method('remindAboutBalance')->willReturn(true);
        $this->notifier->expects(self::never())->method('notify');

        self::assertTrue($this->executor->execute($this->action()));
    }

    /** Bez nastaveného příjemce se upozornění nezařadí — příští běh to zkusí znovu. */
    public function testRetriesWhenNotificationWasNotQueued(): void
    {
        $this->guestMessages->method('remindAboutBalance')->willReturn(false);
        $this->notifier->expects(self::exactly(2))->method('notify')->willReturn(false);
        $action = $this->action();

        self::assertFalse($this->executor->execute($action));
        self::assertFalse($this->executor->execute($action));
    }

    private function action(): ReservationAction
    {
        $reservation = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('2026-10-10'));

        return new ReservationAction($reservation, ActionType::BALANCE_REMINDER, new \DateTimeImmutable('2026-10-01'));
    }
}
