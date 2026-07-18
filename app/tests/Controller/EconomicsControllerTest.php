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
use App\Entity\GuestDocument;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Entity\Setting;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\DocumentType;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class EconomicsControllerTest extends WebTestCase
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
        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . GuestDocument::class . ' g')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('economics-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);

        // Web rezervace s cenou v CZK — vstoupí do přehledu.
        $web = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-03-20'));
        $web->setCheckOut(new \DateTimeImmutable('2026-03-22'));
        $web->setStatus(ReservationStatus::CONFIRMED);
        $web->setGuestName('Miluše Testová');
        $web->setGuestsAdult(2);
        $web->setPriceTotal('4455.00');
        $web->setPriceCurrency('CZK');
        $this->em->persist($web);

        // Budoucí rezervace — patří do "Očekáváno", ne do uskutečněných.
        $future = new Reservation(Channel::WEB, new \DateTimeImmutable('+30 days'));
        $future->setCheckOut(new \DateTimeImmutable('+32 days'));
        $future->setStatus(ReservationStatus::CONFIRMED);
        $future->setGuestName('Budoucí Host');
        $future->setGuestsAdult(2);
        $future->setPriceTotal('3000.00');
        $future->setPriceCurrency('CZK');
        $this->em->persist($future);

        // Zrušená rezervace — nesmí se objevit.
        $cancelled = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-06-01'));
        $cancelled->setCheckOut(new \DateTimeImmutable('2026-06-03'));
        $cancelled->setStatus(ReservationStatus::CANCELLED);
        $cancelled->setGuestName('Zrušený Host');
        $this->em->persist($cancelled);

        $this->em->flush();

        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'economics-test@example.com']));
    }

    public function testOverviewRendersReservationAndTotals(): void
    {
        $this->client->request('GET', '/ekonomika/2026');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ekonomika 2026', $body);
        self::assertStringContainsString('Miluše Testová', $body);
        // příjem 4455 CZK
        self::assertStringContainsString('4 455', $body);
        // souhrn dle kanálu obsahuje Web
        self::assertStringContainsString('Souhrn dle kanálu', $body);
    }

    public function testCancelledReservationIsExcluded(): void
    {
        $this->client->request('GET', '/ekonomika/2026');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Zrušený Host', $body);
    }

    public function testFutureReservationIsSplitAsExpected(): void
    {
        $year = (new \DateTimeImmutable('+30 days'))->format('Y');
        $this->client->request('GET', '/ekonomika/' . $year);

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Budoucí Host', $body);
        self::assertStringContainsString('Očekáváno', $body);
        self::assertStringContainsString('Uskutečněno', $body);
        // budoucí řádek je značený jako plán
        self::assertStringContainsString('plán', $body);
    }

    public function testOverviewWithoutYearDefaultsToCurrent(): void
    {
        $this->client->request('GET', '/ekonomika');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Ekonomika ' . (new \DateTimeImmutable('today'))->format('Y'), $body);
    }

    public function testRecreationFeeReportRendersTotals(): void
    {
        $this->client->request('GET', '/ekonomika/poplatky/2026');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Rekreační poplatek 2026', $body);
        self::assertStringContainsString('Miluše Testová', $body);
        // uskutečněný web pobyt: 15 Kč × 2 dospělí × 2 noci = 60 Kč
        self::assertStringContainsString('60 Kč', $body);
        // celkem včetně plánovaného pobytu (další 2 dospělí × 2 noci) = 120 Kč
        self::assertStringContainsString('120 Kč', $body);
        // zrušená rezervace do podkladu nepatří
        self::assertStringNotContainsString('Zrušený Host', $body);
    }

    public function testRecreationFeeCsvDownloads(): void
    {
        $this->client->request('GET', '/ekonomika/poplatky/2026/podklad.csv');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = (string) $response->getContent();
        self::assertStringContainsString('Miluše Testová', $csv);
        self::assertStringContainsString('Celkem', $csv);
    }

    public function testGuestBookRendersConfirmedGuest(): void
    {
        $this->seedGuest('Evidovaný', 'CZ998877', 'Lipová 14, Tábor', confirmed: true);
        $this->seedGuest('Rozpracovaný', 'CZ111000', 'Nádražní 2, Tábor', confirmed: false);

        $this->client->request('GET', '/ekonomika/kniha-hostu/2026');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Kniha hostů 2026', $body);
        self::assertStringContainsString('Evidovaný', $body);
        self::assertStringContainsString('CZ998877', $body);
        self::assertStringContainsString('Lipová 14, Tábor', $body);
        // nepotvrzený doklad do evidenční knihy nepatří
        self::assertStringNotContainsString('Rozpracovaný', $body);
    }

    public function testGuestBookCsvDownloads(): void
    {
        $this->seedGuest('Evidovaný', 'CZ998877', 'Lipová 14, Tábor', confirmed: true);

        $this->client->request('GET', '/ekonomika/kniha-hostu/2026/kniha.csv');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = (string) $response->getContent();
        self::assertStringContainsString('Evidovaný', $csv);
        self::assertStringContainsString('CZ998877', $csv);
        self::assertStringContainsString('Lipová 14, Tábor', $csv);
    }

    private function seedGuest(string $lastName, string $documentNumber, string $residence, bool $confirmed): void
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-05-10'));
        $r->setCheckOut(new \DateTimeImmutable('2026-05-13'));
        $r->setStatus(ReservationStatus::COMPLETED);
        $r->setGuestName($lastName);
        $this->em->persist($r);

        $doc = new GuestDocument($r, 'Adam', $lastName, new \DateTimeImmutable('1990-01-01'));
        $doc->setIsCzechCitizen(true);
        $doc->setDocumentType(DocumentType::ID_CARD);
        $doc->setDocumentNumber($documentNumber);
        $doc->setResidenceAddress($residence);
        if ($confirmed) {
            $doc->confirm();
        }
        $this->em->persist($doc);
        $this->em->flush();
    }
}
