<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Timeline;

use App\Cashflow\IncomeUpserter;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Entity\ReservationReceipt;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Enum\ReservationStatus;
use App\Reservation\Event\ReservationFinancialsChangedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Splněná akce se uzavře událostí (vystavena faktura / dorazila platba), aniž
 * by se čekalo na její naplánovaný čas — a nesplněná zůstane otevřená.
 */
final class SettleTimelineActionsListenerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IncomeUpserter $income;
    private EventDispatcherInterface $dispatcher;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->income = $container->get(IncomeUpserter::class);
        $this->dispatcher = $container->get('event_dispatcher');

        $this->em->createQuery('DELETE FROM ' . ReservationAction::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . ReservationReceipt::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
    }

    public function testManualPaymentClosesBalanceReminder(): void
    {
        $reservation = $this->reservation('1000.00');
        $action = $this->plan($reservation, ActionType::BALANCE_REMINDER);

        // Ruční platba srovná doplatek → připomínka je zbytečná a uzavře se sama.
        $this->income->recordManualPayment($reservation, '1000.00', new \DateTimeImmutable('today'));

        $this->em->refresh($action);
        self::assertSame(ActionStatus::DONE, $action->getStatus());
    }

    public function testPartialPaymentLeavesBalanceReminderOpen(): void
    {
        $reservation = $this->reservation('1000.00');
        $action = $this->plan($reservation, ActionType::BALANCE_REMINDER);

        $this->income->recordManualPayment($reservation, '400.00', new \DateTimeImmutable('today'));

        $this->em->refresh($action);
        self::assertSame(ActionStatus::PLANNED, $action->getStatus());
    }

    public function testIssuedFinalInvoiceClosesIssueFinalActionIdempotently(): void
    {
        $reservation = $this->reservation('1000.00');
        $action = $this->plan($reservation, ActionType::ISSUE_FINAL_INVOICE);
        $this->persistFinalInvoice($reservation);

        $this->dispatcher->dispatch(new ReservationFinancialsChangedEvent($reservation));
        $this->em->refresh($action);
        self::assertSame(ActionStatus::DONE, $action->getStatus());

        // Opakovaná událost nic nerozbije.
        $this->dispatcher->dispatch(new ReservationFinancialsChangedEvent($reservation));
        $this->em->refresh($action);
        self::assertSame(ActionStatus::DONE, $action->getStatus());
    }

    private function reservation(string $priceTotal): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('+5 days'));
        $r->setCheckOut(new \DateTimeImmutable('+7 days'));
        $r->setGuestName('Jan Novák');
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setBillingMode(BillingMode::STANDARD_WITH_DEPOSIT);
        $r->setPriceTotal($priceTotal);
        $r->setPriceCurrency('CZK');
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    private function plan(Reservation $reservation, ActionType $type): ReservationAction
    {
        $action = new ReservationAction($reservation, $type, new \DateTimeImmutable('+4 days'));
        $this->em->persist($action);
        $this->em->flush();

        return $action;
    }

    private function persistFinalInvoice(Reservation $reservation): void
    {
        $invoice = new Invoice('TEST-FINAL-1', 2026, 1, InvoiceType::FINAL, $reservation, new \DateTimeImmutable('today'), new \DateTimeImmutable('+2 days'));
        $invoice->setTotalAmount('1000.00');
        $this->em->persist($invoice);
        $this->em->flush();
    }
}
