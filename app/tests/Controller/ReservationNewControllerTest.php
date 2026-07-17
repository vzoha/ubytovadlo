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
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ReservationNewControllerTest extends WebTestCase
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
        $user = new User('new-reservation-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'new-reservation-test@example.com']));
    }

    public function testFormRenders(): void
    {
        $this->client->request('GET', '/rezervace/nova');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Nová rezervace', (string) $this->client->getResponse()->getContent());
    }

    public function testCreatesDirectReservation(): void
    {
        $crawler = $this->client->request('GET', '/rezervace/nova');
        $form = $crawler->selectButton('Přidat rezervaci')->form();
        $form['reservation_manual[checkIn]'] = '2026-08-10';
        $form['reservation_manual[checkOut]'] = '2026-08-14';
        $form['reservation_manual[guestName]'] = 'Přímý Host';
        $form['reservation_manual[guestsAdult]'] = '2';
        $form['reservation_manual[guestsChild]'] = '0';
        $form['reservation_manual[priceTotal]'] = '8 500,50';
        $form['reservation_manual[billingMode]'] = BillingMode::ADMIN_BOOKING->value;
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Přímý Host', (string) $this->client->getResponse()->getContent());

        $reservation = $this->em->getRepository(Reservation::class)->findOneBy(['guestName' => 'Přímý Host']);
        self::assertInstanceOf(Reservation::class, $reservation);
        self::assertSame(Channel::DIRECT, $reservation->getChannel());
        self::assertSame(ReservationStatus::CONFIRMED, $reservation->getStatus());
        self::assertSame(BillingMode::ADMIN_BOOKING, $reservation->getBillingMode());
        self::assertSame('8500.50', $reservation->getPriceTotal());
        self::assertSame('CZK', $reservation->getPriceCurrency());
        self::assertSame('CZ', $reservation->getGuestAddress()->getCountry());
        self::assertTrue($reservation->isGuestsSplitManually());
        self::assertSame('2026-08-14', $reservation->getCheckOut()?->format('Y-m-d'));
    }

    /** Nečíselná cena se nesmí tiše uložit jako 0 — host by dostal fakturu na nulu. */
    public function testRejectsNonNumericPrice(): void
    {
        $crawler = $this->client->request('GET', '/rezervace/nova');
        $form = $crawler->selectButton('Přidat rezervaci')->form();
        $form['reservation_manual[checkIn]'] = '2026-08-10';
        $form['reservation_manual[checkOut]'] = '2026-08-14';
        $form['reservation_manual[guestName]'] = 'Chybná Cena';
        $form['reservation_manual[guestsAdult]'] = '2';
        $form['reservation_manual[guestsChild]'] = '0';
        $form['reservation_manual[priceTotal]'] = 'osm tisíc';
        $form['reservation_manual[billingMode]'] = BillingMode::ADMIN_BOOKING->value;
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Cenu zadej číslem', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->em->getRepository(Reservation::class)->findOneBy(['guestName' => 'Chybná Cena']));
    }

    public function testRejectsCheckoutBeforeCheckin(): void
    {
        $crawler = $this->client->request('GET', '/rezervace/nova');
        $form = $crawler->selectButton('Přidat rezervaci')->form();
        $form['reservation_manual[checkIn]'] = '2026-08-10';
        $form['reservation_manual[checkOut]'] = '2026-08-08';
        $form['reservation_manual[guestName]'] = 'Špatný Termín';
        $form['reservation_manual[guestsAdult]'] = '1';
        $form['reservation_manual[guestsChild]'] = '0';
        $this->client->submit($form);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Odjezd musí být po příjezdu', (string) $this->client->getResponse()->getContent());
        self::assertNull($this->em->getRepository(Reservation::class)->findOneBy(['guestName' => 'Špatný Termín']));
    }
}
