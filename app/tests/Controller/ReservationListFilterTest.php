<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Cleaning;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ReservationListFilterTest extends WebTestCase
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

        $this->em->createQuery('DELETE FROM ' . Cleaning::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('list-filter@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'list-filter@example.com']));
    }

    public function testDefaultListOmitsCancelledReservations(): void
    {
        $this->reservation('+10 days', ReservationStatus::CONFIRMED, 'Potvrzený Host');
        $this->reservation('+20 days', ReservationStatus::CANCELLED, 'Zrušený Host');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/rezervace');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('tbody')->text();
        self::assertStringContainsString('Potvrzený Host', $body);
        self::assertStringNotContainsString('Zrušený Host', $body);
    }

    public function testCancelledFilterShowsOnlyCancelledReservations(): void
    {
        $this->reservation('+10 days', ReservationStatus::CONFIRMED, 'Potvrzený Host');
        $this->reservation('+20 days', ReservationStatus::CANCELLED, 'Zrušený Host');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/rezervace?status=cancelled');

        self::assertResponseIsSuccessful();
        $body = $crawler->filter('tbody')->text();
        self::assertStringContainsString('Zrušený Host', $body);
        self::assertStringNotContainsString('Potvrzený Host', $body);
    }

    private function reservation(string $checkIn, ReservationStatus $status, string $guest): Reservation
    {
        $r = new Reservation(Channel::BOOKING, new \DateTimeImmutable($checkIn));
        $r->setCheckOut((new \DateTimeImmutable($checkIn))->modify('+3 days'));
        $r->setStatus($status);
        $r->setGuestName($guest);
        $r->setPriceTotal('1000.00')->setPriceCurrency('CZK');
        $this->em->persist($r);

        return $r;
    }
}
