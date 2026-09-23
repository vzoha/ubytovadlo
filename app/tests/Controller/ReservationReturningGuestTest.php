<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Embeddable\GuestContact;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ReservationReturningGuestTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->getConnection()->executeStatement('DELETE FROM reservation');
        $this->em->getConnection()->executeStatement('DELETE FROM customer');
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('returning-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'returning-test@example.com']));
    }

    private function stay(string $checkIn, ReservationStatus $status = ReservationStatus::COMPLETED): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable($checkIn));
        $r->setCheckOut((new \DateTimeImmutable($checkIn))->modify('+2 days'));
        $r->setStatus($status);
        $r->setGuestName('Jana Testová');
        $r->setGuestContact(new GuestContact('jana@example.com'));
        $this->em->persist($r);

        return $r;
    }

    public function testReturningGuestShowsOrdinalAndOtherStays(): void
    {
        $first = $this->stay('2025-06-01');
        $this->stay('2025-09-01', ReservationStatus::CANCELLED);
        $current = $this->stay('2026-06-01', ReservationStatus::CONFIRMED);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reservation/' . $current->getId());
        self::assertResponseIsSuccessful();

        self::assertSelectorTextContains('.badge.text-bg-info', 'Vracející se host · 2. pobyt');
        self::assertCount(1, $crawler->filter('a[href="/reservation/' . $first->getId() . '"]'));
    }

    public function testFirstTimeGuestHasNoBadge(): void
    {
        $only = $this->stay('2026-06-01', ReservationStatus::CONFIRMED);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reservation/' . $only->getId());
        self::assertResponseIsSuccessful();

        self::assertStringNotContainsString('Vracející se host', $crawler->text());
        self::assertStringNotContainsString('Další pobyty', $crawler->text());
    }
}
