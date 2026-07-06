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
use App\Enum\ActionType;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\ReservationActionRepository;
use App\Timeline\ReservationActionPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReservationActionPlannerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReservationActionPlanner $planner;
    private ReservationActionRepository $actions;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->planner = $container->get(ReservationActionPlanner::class);
        $this->actions = $container->get(ReservationActionRepository::class);

        $this->em->createQuery('DELETE FROM ' . ReservationAction::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
    }

    public function testPlansDepositFlowAndIsIdempotent(): void
    {
        $r = $this->confirmed(BillingMode::STANDARD_WITH_DEPOSIT, 'CZ');

        $added = $this->planner->planFor($r);
        $this->em->flush();

        // žádost o zálohu + pre-arrival + post-stay + issue-final + balance-reminder
        self::assertSame(5, $added);
        self::assertTrue($this->actions->hasOfType($r, ActionType::RESERVATION_REQUEST_MESSAGE));
        self::assertTrue($this->actions->hasOfType($r, ActionType::PRE_ARRIVAL_MESSAGE));
        self::assertTrue($this->actions->hasOfType($r, ActionType::ISSUE_FINAL_INVOICE));
        self::assertTrue($this->actions->hasOfType($r, ActionType::BALANCE_REMINDER));
        self::assertFalse($this->actions->hasOfType($r, ActionType::UBYPORT_EXPORT));

        // Druhé spuštění nic nepřidá (a respektuje případné zrušení).
        self::assertSame(0, $this->planner->planFor($r));
    }

    public function testForeignerGetsUbyportAction(): void
    {
        $r = $this->confirmed(BillingMode::AIRBNB, 'DE');

        $this->planner->planFor($r);
        $this->em->flush();

        self::assertTrue($this->actions->hasOfType($r, ActionType::UBYPORT_EXPORT));
        self::assertFalse($this->actions->hasOfType($r, ActionType::ISSUE_FINAL_INVOICE));
        // OTA kanál zálohu neřeší → žádná žádost o zálohu.
        self::assertFalse($this->actions->hasOfType($r, ActionType::RESERVATION_REQUEST_MESSAGE));
    }

    public function testSkipsPastStay(): void
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2020-01-01'));
        $r->setCheckOut(new \DateTimeImmutable('2020-01-03'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $this->em->persist($r);
        $this->em->flush();

        self::assertSame(0, $this->planner->planFor($r));
    }

    private function confirmed(BillingMode $mode, string $country): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('+10 days'));
        $r->setCheckOut(new \DateTimeImmutable('+12 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setBillingMode($mode);
        $r->setGuestCountry($country);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }
}
