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
    /** Počitadlo pokusů drží cache na disku, takže každý běh potřebuje vlastní adresu. */
    private string $ip;

    protected function setUp(): void
    {
        $this->ip = sprintf('10.%d.%d.%d', random_int(0, 255), random_int(0, 255), random_int(1, 254));
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

    private function submit(string $code, string $lastName): void
    {
        $server = ['REMOTE_ADDR' => $this->ip, 'HTTP_ACCEPT_LANGUAGE' => 'cs'];
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

        $this->submit('hmewrf 28cn', 'franz');

        self::assertResponseRedirects('/checkin/' . $r->getCheckinToken());
    }

    public function testDiacriticsInLastNameDoNotMatter(): void
    {
        $r = $this->persist('HMABCDE123', 'Markéta Dvořáková');

        $this->submit('HMABCDE123', 'dvorakova');

        self::assertResponseRedirects('/checkin/' . $r->getCheckinToken());
    }

    public function testWrongLastNameFails(): void
    {
        $this->persist('HMEWRF28CN', 'Richard Franz');

        $this->submit('HMEWRF28CN', 'Novak');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.alert, .invalid-feedback, form', 'nepodařilo najít');
    }

    public function testUnknownCodeFailsWithTheSameMessage(): void
    {
        $this->persist('HMEWRF28CN', 'Richard Franz');

        $this->submit('HMXXXXXXXX', 'Franz');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'nepodařilo najít');
    }

    public function testCancelledReservationIsNotFound(): void
    {
        $this->persist('HMEWRF28CN', 'Richard Franz', status: ReservationStatus::CANCELLED);

        $this->submit('HMEWRF28CN', 'Franz');

        self::assertResponseStatusCodeSame(422);
    }

    public function testLongPastStayIsNotFound(): void
    {
        $this->persist('HMEWRF28CN', 'Richard Franz', checkIn: '-2 years');

        $this->submit('HMEWRF28CN', 'Franz');

        self::assertResponseStatusCodeSame(422);
    }

    public function testAttemptsFromOneAddressAreLimited(): void
    {
        $this->persist('HMEWRF28CN', 'Richard Franz');
        for ($i = 0; $i < 10; $i++) {
            $this->submit('HMXXXXXXXX', 'Novak');
            self::assertResponseStatusCodeSame(422);
        }

        $this->submit('HMEWRF28CN', 'Franz');
        self::assertResponseStatusCodeSame(429);
    }
}
