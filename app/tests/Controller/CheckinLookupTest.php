<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\GuestDocument;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Vstup do check-inu bez tokenu: kód rezervace plus příjmení vede na tokenovou
 * URL, cokoli jiného skončí stejnou hláškou a počet pokusů z jedné IP je omezený.
 */
final class CheckinLookupTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->createQuery('DELETE FROM ' . GuestDocument::class . ' g')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->flush();
    }

    private function persist(
        string $externalId,
        string $guestName,
        string $checkIn = '+5 days',
        ReservationStatus $status = ReservationStatus::CONFIRMED,
    ): Reservation {
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable($checkIn));
        $r->setCheckOut((new \DateTimeImmutable($checkIn))->modify('+3 days'));
        $r->setExternalId($externalId);
        $r->setGuestName($guestName);
        $r->setStatus($status);
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    /**
     * Každý test má vlastní IP, aby si testy navzájem nevyčerpaly limit pokusů.
     */
    private function submit(string $code, string $lastName, string $ip): void
    {
        $server = ['REMOTE_ADDR' => $ip, 'HTTP_ACCEPT_LANGUAGE' => 'cs'];
        $crawler = $this->client->request('GET', '/checkin', server: $server);
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['checkin_lookup[code]'] = $code;
        $form['checkin_lookup[lastName]'] = $lastName;
        $this->client->submit($form, serverParameters: $server);
    }

    public function testCodeAndLastNameRedirectToTokenUrl(): void
    {
        $r = $this->persist('HMEWRF28CN', 'Richard Franz');

        $this->submit('hmewrf 28cn', 'franz', '10.0.0.1');

        self::assertResponseRedirects('/checkin/' . $r->getCheckinToken());
    }

    public function testDiacriticsInLastNameDoNotMatter(): void
    {
        $r = $this->persist('HMABCDE123', 'Markéta Dvořáková');

        $this->submit('HMABCDE123', 'dvorakova', '10.0.0.2');

        self::assertResponseRedirects('/checkin/' . $r->getCheckinToken());
    }

    public function testWrongLastNameFails(): void
    {
        $this->persist('HMEWRF28CN', 'Richard Franz');

        $this->submit('HMEWRF28CN', 'Novak', '10.0.0.3');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert, .invalid-feedback, form', 'nepodařilo najít');
    }

    public function testUnknownCodeFailsWithTheSameMessage(): void
    {
        $this->persist('HMEWRF28CN', 'Richard Franz');

        $this->submit('HMXXXXXXXX', 'Franz', '10.0.0.4');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'nepodařilo najít');
    }

    public function testCancelledReservationIsNotFound(): void
    {
        $this->persist('HMEWRF28CN', 'Richard Franz', status: ReservationStatus::CANCELLED);

        $this->submit('HMEWRF28CN', 'Franz', '10.0.0.5');

        self::assertResponseStatusCodeSame(422);
    }

    public function testLongPastStayIsNotFound(): void
    {
        $this->persist('HMEWRF28CN', 'Richard Franz', checkIn: '-2 years');

        $this->submit('HMEWRF28CN', 'Franz', '10.0.0.6');

        self::assertResponseStatusCodeSame(422);
    }

    public function testAttemptsFromOneAddressAreLimited(): void
    {
        $this->persist('HMEWRF28CN', 'Richard Franz');
        // Počitadlo pokusů drží cache na disku, aby přežilo mezi requesty.
        // Adresa je proto pro každý běh jiná — jinak by okno 15 minut
        // přeteklo z minulého běhu.
        $ip = '10.1.' . random_int(0, 255) . '.' . random_int(0, 255);

        for ($i = 0; $i < 10; $i++) {
            $this->submit('HMXXXXXXXX', 'Novak', $ip);
            self::assertResponseStatusCodeSame(422);
        }

        $this->submit('HMEWRF28CN', 'Franz', $ip);
        self::assertResponseStatusCodeSame(429);
    }
}
