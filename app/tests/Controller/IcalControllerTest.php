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
use App\Entity\Setting;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Ical\IcalFeedToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IcalControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $token;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();

        $active = new Reservation(Channel::DIRECT, new \DateTimeImmutable('+10 days'));
        $active->setCheckOut(new \DateTimeImmutable('+14 days'));
        $active->setStatus(ReservationStatus::CONFIRMED);
        $active->setGuestName('Tajný Host');
        $this->em->persist($active);

        $cancelled = new Reservation(Channel::BOOKING, new \DateTimeImmutable('+20 days'));
        $cancelled->setCheckOut(new \DateTimeImmutable('+22 days'));
        $cancelled->setStatus(ReservationStatus::CANCELLED);
        $this->em->persist($cancelled);

        $this->token = str_repeat('a', 64);
        $this->em->persist(new Setting(IcalFeedToken::SETTING_KEY, $this->token));
        $this->em->flush();
    }

    public function testValidTokenReturnsCalendarWithoutLogin(): void
    {
        $this->client->request('GET', '/ical/' . $this->token . '.ics');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/calendar; charset=utf-8');
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('BEGIN:VCALENDAR', $body);
        self::assertStringContainsString('BEGIN:VEVENT', $body);
    }

    public function testCancelledReservationAndGuestNameAreNotExported(): void
    {
        $this->client->request('GET', '/ical/' . $this->token . '.ics');

        $body = (string) $this->client->getResponse()->getContent();
        // Právě jeden blok (zrušená rezervace vynechaná).
        self::assertSame(1, substr_count($body, 'BEGIN:VEVENT'));
        self::assertStringNotContainsString('Tajný Host', $body);
    }

    public function testWrongTokenIsNotFound(): void
    {
        $this->client->request('GET', '/ical/' . str_repeat('0', 64) . '.ics');

        self::assertResponseStatusCodeSame(404);
    }
}
