<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AirbnbStatement;
use App\Entity\BookingMonthlyInvoice;
use App\Entity\Embeddable\VatReverseCharge;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Entity\User;
use App\Entity\VatPeriod;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DashboardControllerTest extends WebTestCase
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

        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . AirbnbStatement::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . BookingMonthlyInvoice::class . ' b')->execute();
        $this->em->createQuery('DELETE FROM ' . VatPeriod::class . ' v')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('dashboard@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'dashboard@example.com']));
    }

    public function testEmptyDashboardRenders(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Přehled', $body);
        self::assertStringContainsString('Úklid', $body);
        self::assertStringContainsString('Doplnit údaje', $body);
        self::assertStringContainsString('Vystavit fakturu', $body);
        self::assertStringContainsString('DPH', $body);
    }

    public function testUpcomingShowsArrivalWithin30Days(): void
    {
        $today = new \DateTimeImmutable('today');
        $r = new Reservation(Channel::WEB, $today->modify('+5 days'));
        $r->setCheckOut($today->modify('+8 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Jan Novák');
        $r->setGuestsAdult(2);
        $this->em->persist($r);
        $this->em->flush();

        $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Jan Novák', $body);
        self::assertStringContainsString('příjezd', $body);
    }

    public function testNeedsDetailsShowsBookingTrigger(): void
    {
        $r = new Reservation(Channel::BOOKING, new \DateTimeImmutable('today +10 days'));
        $r->setExternalId('ABC-123');
        $this->em->persist($r);
        $this->em->flush();

        $this->client->request('GET', '/');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('#ABC-123', $body);
        self::assertStringContainsString('Doplnit', $body);
    }

    public function testMissingInvoiceForCheckedOutAirbnb(): void
    {
        $today = new \DateTimeImmutable('today');
        $r = new Reservation(Channel::AIRBNB, $today->modify('-5 days'));
        $r->setCheckOut($today->modify('-2 days'));
        $r->setStatus(ReservationStatus::COMPLETED);
        $r->setBillingMode(BillingMode::AIRBNB);
        $r->setGuestName('Petra Veselá');
        $r->setPriceTotal('5400');
        $this->em->persist($r);
        $this->em->flush();

        $this->client->request('GET', '/');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('Petra Veselá', $body);
        self::assertStringContainsString('Faktura', $body);
    }

    public function testMissingInvoiceHiddenWhenFullInvoiceExists(): void
    {
        $today = new \DateTimeImmutable('today');
        $r = new Reservation(Channel::AIRBNB, $today->modify('-5 days'));
        $r->setCheckOut($today->modify('-2 days'));
        $r->setStatus(ReservationStatus::COMPLETED);
        $r->setBillingMode(BillingMode::AIRBNB);
        $r->setGuestName('Already Invoiced');
        $this->em->persist($r);

        $invoice = new Invoice(
            number: '2026999',
            seriesYear: 2026,
            seriesSequence: 999,
            type: InvoiceType::FULL,
            reservation: $r,
            issuedAt: $today,
            dueAt: $today,
        );
        $this->em->persist($invoice);
        $this->em->flush();

        $this->client->request('GET', '/');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('Already Invoiced', $body);
    }

    public function testVatPendingMonthShowsOverdueWithMissingPdf(): void
    {
        // Cíl: měsíc, jehož termín (25. následujícího měsíce) je už po dnešku.
        // -3 měsíce od dneška spolehlivě splní pro libovolný den v měsíci.
        $today = new \DateTimeImmutable('today');
        $target = $today->modify('first day of -3 months');
        $checkIn = $target->modify('+9 days');
        $checkOut = $target->modify('+14 days');

        $r = new Reservation(Channel::BOOKING, $checkIn);
        $r->setCheckOut($checkOut);
        $r->setStatus(ReservationStatus::COMPLETED);
        $r->setCommissionAmount('20.00');
        $r->setCommissionCurrency('EUR');
        $r->setVatReverseCharge(new VatReverseCharge(
            duzp: $target->modify('last day of this month'),
            baseCzk: '500.00',
            amountCzk: '105.00',
        ));
        $this->em->persist($r);
        $this->em->flush();

        $this->client->request('GET', '/');

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('PO TERMÍNU', $body);
        self::assertStringContainsString('chybí Booking PDF', $body);
    }
}
