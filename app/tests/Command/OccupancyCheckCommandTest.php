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
use App\Enum\Channel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class OccupancyCheckCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Application $application;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        // Faktury (RESTRICT) smazat před rezervacemi; úklidy odejdou kaskádou.
        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->application = new Application(self::$kernel);
    }

    public function testReportsOverlapAndFails(): void
    {
        $future = (new \DateTimeImmutable('today'))->modify('+10 days');
        $this->reservation($future, $future->modify('+5 days'), Channel::WEB);
        $this->reservation($future->modify('+2 days'), $future->modify('+7 days'), Channel::BOOKING);
        $this->em->flush();

        $tester = $this->runCheck();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('překrývajících', $tester->getDisplay());
    }

    public function testNoConflictSucceeds(): void
    {
        $future = (new \DateTimeImmutable('today'))->modify('+10 days');
        $this->reservation($future, $future->modify('+3 days'), Channel::WEB);
        $this->reservation($future->modify('+5 days'), $future->modify('+8 days'), Channel::BOOKING);
        $this->em->flush();

        $tester = $this->runCheck();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    private function runCheck(): CommandTester
    {
        $tester = new CommandTester($this->application->find('app:occupancy:check'));
        $tester->execute([]);

        return $tester;
    }

    private function reservation(\DateTimeImmutable $checkIn, \DateTimeImmutable $checkOut, Channel $channel): void
    {
        $r = new Reservation($channel, $checkIn);
        $r->setCheckOut($checkOut);
        $r->setGuestName('Host');
        $this->em->persist($r);
    }
}
