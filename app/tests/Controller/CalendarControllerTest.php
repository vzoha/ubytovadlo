<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CalendarControllerTest extends WebTestCase
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

        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('calendar-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);

        // Pobyt přes přelom srpna a září — musí být vidět v obou měsících.
        $this->reservation(Channel::WEB, '2026-08-28', '2026-09-03', 'Novak Prechod', ReservationStatus::CONFIRMED);
        // Dvojí prodej: dva kanály na stejném termínu.
        $this->reservation(Channel::BOOKING, '2026-09-10', '2026-09-14', 'Booking Kolize', ReservationStatus::CONFIRMED);
        $this->reservation(Channel::AIRBNB, '2026-09-12', '2026-09-16', 'Airbnb Kolize', ReservationStatus::CONFIRMED);
        // Odjezd a příjezd ve stejný den se nesmí počítat jako kolize.
        $this->reservation(Channel::WEB, '2026-09-16', '2026-09-20', 'Navazuje Hned', ReservationStatus::NEEDS_DETAILS);
        // Zrušená rezervace termín neblokuje.
        $this->reservation(Channel::WEB, '2026-09-22', '2026-09-25', 'Zrusena Rezervace', ReservationStatus::CANCELLED);

        $this->em->flush();

        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'calendar-test@example.com']));
    }

    public function testTimelineShowsMonthWithStaysAndSkipsCancelled(): void
    {
        $this->client->request('GET', '/kalendar?pohled=osa&mesic=2026-09');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Září 2026', $body);
        self::assertStringContainsString('Novak Prechod', $body);
        self::assertStringContainsString('Navazuje Hned', $body);
        self::assertStringNotContainsString('Zrusena Rezervace', $body);
    }

    public function testStayFromPreviousMonthShowsInBothMonths(): void
    {
        $this->client->request('GET', '/kalendar?pohled=osa&mesic=2026-08');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Novak Prechod', (string) $this->client->getResponse()->getContent());
    }

    public function testOverlappingStaysAreReportedAsConflict(): void
    {
        $this->client->request('GET', '/kalendar?mesic=2026-09');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Dvojí obsazení termínu', $body);
        // Kolidují právě dvě rezervace — navazující pobyt mezi ně nepatří.
        self::assertStringContainsString('2 rezervace', $body);
        self::assertSame(2, substr_count($body, ' cal-bar--conflict'));
    }

    public function testGridViewRendersWeeks(): void
    {
        $this->client->request('GET', '/kalendar?pohled=mesic&mesic=2026-09');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        // Září 2026 se vejde do pěti týdnů.
        self::assertSame(5, substr_count($body, 'class="cal-week bg-white"'));
        self::assertStringContainsString('Booking Kolize', $body);
    }

    public function testChosenViewSurvivesToNextRequest(): void
    {
        $this->client->request('GET', '/kalendar?pohled=mesic');
        $this->client->request('GET', '/kalendar');

        self::assertStringContainsString('cal-week', (string) $this->client->getResponse()->getContent());
    }

    public function testBrokenMonthFallsBackToToday(): void
    {
        $this->client->request('GET', '/kalendar?mesic=rozbite');

        self::assertResponseIsSuccessful();
        $today = new \DateTimeImmutable('today');
        self::assertStringContainsString((string) $today->format('Y'), (string) $this->client->getResponse()->getContent());
    }

    private function reservation(Channel $channel, string $checkIn, string $checkOut, string $guest, ReservationStatus $status): void
    {
        $reservation = new Reservation($channel, new \DateTimeImmutable($checkIn));
        $reservation->setCheckOut(new \DateTimeImmutable($checkOut));
        $reservation->setGuestName($guest);
        $reservation->setStatus($status);
        $this->em->persist($reservation);
    }
}
