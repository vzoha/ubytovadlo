<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Customer\CustomerDuplicateFinder;
use App\Customer\CustomerMerger;
use App\Entity\Customer;
use App\Entity\CustomerDistinctPair;
use App\Entity\Embeddable\GuestContact;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CustomerMergerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CustomerMerger $merger;
    private CustomerDuplicateFinder $finder;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->merger = $container->get(CustomerMerger::class);
        $this->finder = $container->get(CustomerDuplicateFinder::class);

        $this->em->getConnection()->executeStatement('DELETE FROM reservation');
        $this->em->getConnection()->executeStatement('DELETE FROM customer');
    }

    private function stay(string $checkIn, string $name, ?string $email = null): Reservation
    {
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable($checkIn));
        $r->setCheckOut((new \DateTimeImmutable($checkIn))->modify('+2 days'));
        $r->setStatus(ReservationStatus::COMPLETED);
        $r->setGuestName($name);
        $r->setGuestContact(new GuestContact($email));
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    public function testMergeMovesStaysFillsContactAndRemovesOther(): void
    {
        $first = $this->stay('2025-06-01', 'Markéta Dvořáková');
        $second = $this->stay('2026-06-01', 'Markéta Dvořáková', 'marketa@example.com');
        $keep = $first->getCustomer();
        $merge = $second->getCustomer();
        self::assertNotNull($keep);
        self::assertNotNull($merge);
        $keep->setNote('Jezdí se psem.');
        $merge->setNote('Chce postýlku.');
        $mergeId = $merge->getId();

        self::assertSame(1, $this->merger->merge($keep, $merge));
        $this->em->clear();

        $kept = $this->em->find(Customer::class, $keep->getId());
        self::assertSame('marketa@example.com', $kept?->getEmail());
        self::assertSame("Jezdí se psem.\n\nChce postýlku.", $kept->getNote());
        self::assertNull($this->em->find(Customer::class, $mergeId));
        self::assertSame($keep->getId(), $this->em->find(Reservation::class, $second->getId())?->getCustomer()?->getId());
    }

    public function testMergeWithItselfDoesNothing(): void
    {
        $customer = $this->stay('2025-06-01', 'Markéta Dvořáková')->getCustomer();
        self::assertNotNull($customer);

        self::assertSame(0, $this->merger->merge($customer, $customer));
        self::assertNotNull($this->em->find(Customer::class, $customer->getId()));
    }

    public function testDetachCreatesSeparateCustomerAndIsNotSuggestedBack(): void
    {
        $a = $this->stay('2025-06-01', 'Jan Novák', 'jan@example.com');
        $b = $this->stay('2026-06-01', 'Jan Novák', 'jan@example.com');
        $original = $a->getCustomer();
        self::assertSame($original, $b->getCustomer());

        $detached = $this->merger->detach($b);

        self::assertNotNull($detached);
        self::assertNotSame($original, $detached);
        self::assertSame($detached, $b->getCustomer());
        self::assertSame('jan@example.com', $detached->getEmail());
        self::assertSame([], $this->finder->findAll(), 'oddělený pobyt se nenabízí zpátky');
    }

    public function testDetachOfOnlyStayDoesNothing(): void
    {
        $only = $this->stay('2025-06-01', 'Jan Novák');
        $customer = $only->getCustomer();

        self::assertNull($this->merger->detach($only));
        self::assertSame($customer, $only->getCustomer());
    }

    public function testMarkDistinctIsIdempotentAndHidesSuggestion(): void
    {
        $a = $this->stay('2025-06-01', 'Markéta Dvořáková')->getCustomer();
        $b = $this->stay('2026-06-01', 'Markéta Dvořáková')->getCustomer();
        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertCount(1, $this->finder->findAll());

        $this->merger->markDistinct($b, $a);
        $this->merger->markDistinct($a, $b);

        self::assertSame([], $this->finder->findAll());
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM customer_distinct_pair'));
    }

    public function testMergeKeepsDistinctDecisionsOfMergedCustomer(): void
    {
        $keep = $this->stay('2025-06-01', 'Markéta Dvořáková')->getCustomer();
        $merge = $this->stay('2026-06-01', 'Markéta Dvořáková', 'marketa@example.com')->getCustomer();
        $other = $this->stay('2026-08-01', 'Markéta Dvořáková')->getCustomer();
        self::assertNotNull($keep);
        self::assertNotNull($merge);
        self::assertNotNull($other);
        $this->merger->markDistinct($merge, $other);

        $this->merger->merge($keep, $merge);

        self::assertNotNull($this->em->getRepository(CustomerDistinctPair::class)->findOneBy([]));
        self::assertSame([], $this->finder->findAll(), 'rozhodnutí o sloučeném hostovi platí i pro ponechaného');
    }
}
