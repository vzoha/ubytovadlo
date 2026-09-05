<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Reservation;

use App\Entity\Embeddable\Address;
use App\Entity\MessageTemplate;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\ReservationActionRepository;
use App\Reservation\ReservationCanceller;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReservationCancellerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReservationCanceller $canceller;
    private ReservationActionRepository $actions;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->canceller = $container->get(ReservationCanceller::class);
        $this->actions = $container->get(ReservationActionRepository::class);

        $this->em->createQuery('DELETE FROM ' . ReservationAction::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . MessageTemplate::class . ' t')->execute();
    }

    public function testCancelClosesPlannedActions(): void
    {
        $reservation = $this->upcomingStay();
        $action = new ReservationAction($reservation, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('+8 days'));
        $this->em->persist($action);
        $this->em->flush();

        $this->canceller->cancel($reservation);

        self::assertSame(ReservationStatus::CANCELLED, $reservation->getStatus());
        self::assertSame(ActionStatus::CANCELLED, $action->getStatus());
        // Zrušené rezervaci nemá cron co poslat.
        self::assertSame([], $this->actions->findDue(new \DateTimeImmutable('+30 days')));
    }

    public function testRestoreBringsBackActionsClosedByCancel(): void
    {
        $reservation = $this->upcomingStay();
        $mine = new ReservationAction($reservation, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('+8 days'));
        $byHand = new ReservationAction($reservation, ActionType::POST_STAY_MESSAGE, new \DateTimeImmutable('+15 days'));
        $byHand->cancel();
        $this->em->persist($mine);
        $this->em->persist($byHand);
        $this->em->flush();

        $this->canceller->cancel($reservation);
        $status = $this->canceller->restore($reservation);

        self::assertSame(ReservationStatus::CONFIRMED, $status);
        self::assertSame(ReservationStatus::CONFIRMED, $reservation->getStatus());
        self::assertSame(ActionStatus::PLANNED, $mine->getStatus());
        // Akci zrušenou ručně obnovení storna nevzkřísí.
        self::assertSame(ActionStatus::CANCELLED, $byHand->getStatus());
    }

    public function testRestoreWithoutGuestDetailsReturnsToNeedsDetails(): void
    {
        $reservation = $this->upcomingStay();
        $reservation->setGuestName(null);
        $this->em->flush();

        $this->canceller->cancel($reservation);

        self::assertSame(ReservationStatus::NEEDS_DETAILS, $this->canceller->restore($reservation));
    }

    private function upcomingStay(): Reservation
    {
        $reservation = new Reservation(Channel::BOOKING, new \DateTimeImmutable('+10 days'));
        $reservation->setCheckOut(new \DateTimeImmutable('+12 days'));
        $reservation->setStatus(ReservationStatus::CONFIRMED);
        $reservation->setBillingMode(BillingMode::BOOKING_COM);
        $reservation->setGuestName('Testovací Host');
        $reservation->setGuestAddress(new Address(country: 'CZ'));
        $this->em->persist($reservation);
        $this->em->flush();

        return $reservation;
    }
}
