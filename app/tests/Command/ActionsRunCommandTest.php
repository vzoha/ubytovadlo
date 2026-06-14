<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ActionsRunCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CommandTester $tester;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . ReservationAction::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();

        $application = new Application(self::$kernel);
        $this->tester = new CommandTester($application->find('app:actions:run'));
    }

    public function testIssueFinalInvoiceActionResolvesWhenInvoiceExists(): void
    {
        $r = $this->reservation();
        $invoice = new Invoice('2026777', 2026, InvoiceType::FINAL, $r, new \DateTimeImmutable(), new \DateTimeImmutable('+14 days'));
        $invoice->setTotalAmount('3000.00');
        $this->em->persist($invoice);

        $due = new ReservationAction($r, ActionType::ISSUE_FINAL_INVOICE, new \DateTimeImmutable('-1 hour'));
        $this->em->persist($due);
        $this->em->flush();

        $this->tester->execute([]);

        $this->em->refresh($due);
        self::assertSame(ActionStatus::DONE, $due->getStatus());
        self::assertNotNull($due->getExecutedAt());
    }

    public function testGuestMessageActionStaysPlanned(): void
    {
        $r = $this->reservation();
        $msg = new ReservationAction($r, ActionType::PRE_ARRIVAL_MESSAGE, new \DateTimeImmutable('-1 hour'));
        $this->em->persist($msg);
        $this->em->flush();

        $this->tester->execute([]);

        $this->em->refresh($msg);
        self::assertSame(ActionStatus::PLANNED, $msg->getStatus());
    }

    private function reservation(): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('-2 days'));
        $r->setCheckOut(new \DateTimeImmutable('today'));
        $r->setGuestName('Test Host');
        $this->em->persist($r);

        return $r;
    }
}
