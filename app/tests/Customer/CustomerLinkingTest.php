<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Entity\Customer;
use App\Entity\Embeddable\GuestContact;
use App\Entity\Reservation;
use App\Enum\Channel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Párování rezervací se zákazníky při uložení (listener) i dodatečně (command).
 */
final class CustomerLinkingTest extends KernelTestCase
{
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->getConnection()->executeStatement('DELETE FROM reservation');
        $this->em->getConnection()->executeStatement('DELETE FROM customer');
    }

    private function reservation(string $checkIn, ?string $email = null, ?string $phone = null, string $name = 'Jana Testová'): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable($checkIn));
        $r->setCheckOut((new \DateTimeImmutable($checkIn))->modify('+2 days'));
        $r->setGuestName($name);
        $r->setGuestContact(new GuestContact($email, $phone));

        return $r;
    }

    private function save(Reservation ...$reservations): void
    {
        foreach ($reservations as $r) {
            $this->em->persist($r);
        }
        $this->em->flush();
    }

    private function customerCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM customer');
    }

    public function testNewReservationGetsNewCustomer(): void
    {
        $r = $this->reservation('2026-06-01', 'jana@example.com', '776 123 456');
        $this->save($r);

        $customer = $r->getCustomer();
        self::assertNotNull($customer);
        self::assertNotNull($customer->getId());
        self::assertSame('Jana Testová', $customer->getDisplayName());
        self::assertSame('jana@example.com', $customer->getEmail());
        self::assertSame('+420776123456', $customer->getPhone());
    }

    public function testSameEmailInOneFlushSharesCustomer(): void
    {
        $a = $this->reservation('2026-06-01', 'jana@example.com');
        $b = $this->reservation('2026-08-01', 'JANA@example.com');
        $this->save($a, $b);

        self::assertSame($a->getCustomer(), $b->getCustomer());
        self::assertSame(1, $this->customerCount());
    }

    public function testLaterStayMatchesByEmailAcrossFlushes(): void
    {
        $first = $this->reservation('2025-06-01', 'jana@example.com');
        $this->save($first);
        $customerId = $first->getCustomer()?->getId();
        $this->em->clear();

        $second = $this->reservation('2026-06-01', 'jana@example.com');
        $this->save($second);

        self::assertSame($customerId, $second->getCustomer()?->getId());
        self::assertSame(1, $this->customerCount());
    }

    public function testMatchesByPhoneAndFillsMissingEmail(): void
    {
        $first = $this->reservation('2025-06-01', phone: '+420 776 123 456');
        $this->save($first);
        $this->em->clear();

        $second = $this->reservation('2026-06-01', 'jana@example.com', '776123456');
        $this->save($second);
        $this->em->clear();

        $customer = $this->em->getRepository(Customer::class)->findAll();
        self::assertCount(1, $customer);
        self::assertSame('jana@example.com', $customer[0]->getEmail());
    }

    public function testEmailMatchWinsOverPhoneMatch(): void
    {
        $byEmail = $this->reservation('2025-06-01', 'jana@example.com');
        $byPhone = $this->reservation('2025-07-01', 'jiny@example.com', '+420776123456');
        $this->save($byEmail, $byPhone);

        $both = $this->reservation('2026-06-01', 'jana@example.com', '+420776123456');
        $this->save($both);

        self::assertSame($byEmail->getCustomer(), $both->getCustomer());
    }

    public function testFilledContactIsNotOverwritten(): void
    {
        $first = $this->reservation('2025-06-01', 'jana@example.com', '+420776123456');
        $this->save($first);

        $second = $this->reservation('2026-06-01', 'jana@example.com', '+420777000111');
        $this->save($second);

        self::assertSame('+420776123456', $second->getCustomer()?->getPhone());
    }

    public function testNameAloneDoesNotCreateCustomer(): void
    {
        $a = $this->reservation('2026-06-01');
        $b = $this->reservation('2026-08-01', 'abc@guest.booking.com', 'nevím');
        $this->save($a, $b);

        self::assertNull($a->getCustomer());
        self::assertNull($b->getCustomer());
        self::assertSame(0, $this->customerCount());
    }

    public function testContactAddedLaterLinksOnUpdate(): void
    {
        $r = $this->reservation('2026-06-01');
        $this->save($r);
        self::assertNull($r->getCustomer());

        $r->setGuestContact(new GuestContact('jana@example.com'));
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->find(Reservation::class, $r->getId());
        self::assertNotNull($reloaded?->getCustomer());
    }

    public function testCommandLinksExistingReservationsIdempotently(): void
    {
        $a = $this->reservation('2025-06-01', 'jana@example.com');
        $b = $this->reservation('2026-06-01', 'jana@example.com');
        $c = $this->reservation('2026-07-01');
        $this->save($a, $b, $c);
        $this->em->getConnection()->executeStatement('UPDATE reservation SET customer_id = NULL');
        $this->em->getConnection()->executeStatement('DELETE FROM customer');
        $this->em->clear();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:customers:link'));
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('spárováno=2', $tester->getDisplay());

        $linked = (int) $this->em->getConnection()->fetchOne('SELECT COUNT(DISTINCT customer_id) FROM reservation WHERE customer_id IS NOT NULL');
        self::assertSame(1, $linked);

        $tester->execute([]);
        self::assertStringContainsString('kandidátů=0', $tester->getDisplay());
    }

    public function testSharedEmailOfDifferentPeopleKeepsSeparateCustomers(): void
    {
        $novak = $this->reservation('2025-06-01', 'sdileny@example.com', name: 'Jan Novák');
        $dvorak = $this->reservation('2025-07-01', 'sdileny@example.com', name: 'Marie Dvořáková');
        $this->save($novak, $dvorak);

        $dvorakAgain = $this->reservation('2026-07-01', 'sdileny@example.com', name: 'Marie Dvořáková');
        $this->save($dvorakAgain);

        self::assertNotSame($novak->getCustomer(), $dvorak->getCustomer());
        self::assertSame($dvorak->getCustomer(), $dvorakAgain->getCustomer());
        self::assertSame(2, $this->customerCount());
    }

    public function testHouseholdSharesCustomer(): void
    {
        $husband = $this->reservation('2025-06-01', 'novakovi@example.com', name: 'Jan Novák');
        $wife = $this->reservation('2026-06-01', 'novakovi@example.com', name: 'Petra Nováková');
        $this->save($husband, $wife);

        self::assertSame($husband->getCustomer(), $wife->getCustomer());
    }
}
