<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Entity\User;
use App\Enum\ActionType;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ReservationStayControllerTest extends WebTestCase
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
        $this->em->createQuery('DELETE FROM ' . ReservationAction::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('stay-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'stay-test@example.com']));
    }

    /** Faktura drží rezervaci přes FK — jiné testy mažou rezervace bez faktur. */
    protected function tearDown(): void
    {
        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        parent::tearDown();
    }

    public function testDirectReservationChangesStayAndPriceAndReplansActions(): void
    {
        $reservation = $this->reservation(Channel::DIRECT);

        $crawler = $this->client->request('GET', '/reservation/' . $reservation->getId());
        self::assertSelectorExists('#editStay');
        $form = $crawler->filter('#editStay')->selectButton('Uložit')->form();
        $form['reservation_stay[checkIn]'] = $this->day('+11 days');
        $form['reservation_stay[checkOut]'] = $this->day('+12 days');
        $form['reservation_stay[priceTotal]'] = '1 800';
        $this->client->submit($form);

        self::assertResponseRedirects('/reservation/' . $reservation->getId());
        $this->em->clear();
        $saved = $this->em->find(Reservation::class, $reservation->getId());
        self::assertInstanceOf(Reservation::class, $saved);
        self::assertSame($this->day('+11 days'), $saved->getCheckIn()->format('Y-m-d'));
        self::assertSame($this->day('+12 days'), $saved->getCheckOut()?->format('Y-m-d'));
        self::assertSame('1800.00', $saved->getPriceTotal());

        $finalInvoice = $this->em->getRepository(ReservationAction::class)
            ->findOneBy(['reservation' => $saved, 'type' => ActionType::ISSUE_FINAL_INVOICE]);
        self::assertInstanceOf(ReservationAction::class, $finalInvoice);
        self::assertSame($this->day('+11 days') . ' 10:00', $finalInvoice->getScheduledFor()->format('Y-m-d H:i'));
    }

    public function testRejectsCheckOutBeforeCheckIn(): void
    {
        $reservation = $this->reservation(Channel::DIRECT);

        $this->client->request('POST', '/reservation/' . $reservation->getId() . '/termin', [
            'reservation_stay' => [
                'checkIn' => $this->day('+12 days'),
                'checkOut' => $this->day('+11 days'),
                'priceTotal' => '3600',
                '_token' => $this->csrfToken($reservation),
            ],
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Odjezd musí být po příjezdu', (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        self::assertSame($this->day('+10 days'), $this->em->find(Reservation::class, $reservation->getId())?->getCheckIn()->format('Y-m-d'));
    }

    public function testChannelWithExternalSourceKeepsStay(): void
    {
        $reservation = $this->reservation(Channel::WEB);

        $crawler = $this->client->request('GET', '/reservation/' . $reservation->getId());
        self::assertSelectorNotExists('#editStay');
        self::assertStringContainsString('mění se tam', $crawler->text());

        $this->client->request('POST', '/reservation/' . $reservation->getId() . '/termin', [
            'reservation_stay' => ['checkIn' => $this->day('+11 days')],
        ]);
        self::assertResponseRedirects('/reservation/' . $reservation->getId());
        $this->em->clear();
        self::assertSame($this->day('+10 days'), $this->em->find(Reservation::class, $reservation->getId())?->getCheckIn()->format('Y-m-d'));
    }

    public function testWarnsAboutIssuedInvoiceWhenStayChanges(): void
    {
        $reservation = $this->reservation(Channel::DIRECT);
        $invoice = new Invoice('2026-0001', 2026, 1, InvoiceType::FINAL, $reservation, new \DateTimeImmutable('today'), null);
        $this->em->persist($invoice);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/reservation/' . $reservation->getId());
        $form = $crawler->filter('#editStay')->selectButton('Uložit')->form();
        $form['reservation_stay[checkIn]'] = $this->day('+11 days');
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-warning', 'Vystavené faktury se nezměnily');
    }

    private function reservation(Channel $channel): Reservation
    {
        $r = new Reservation($channel, new \DateTimeImmutable($this->day('+10 days')));
        $r->setCheckOut(new \DateTimeImmutable($this->day('+12 days')));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setBillingMode(BillingMode::STANDARD_WITH_DEPOSIT);
        $r->setGuestName('Testovací Host');
        $r->setPriceTotal('3600.00');
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    private function csrfToken(Reservation $reservation): string
    {
        $crawler = $this->client->request('GET', '/reservation/' . $reservation->getId());

        return (string) $crawler->filter('#editStay input[name="reservation_stay[_token]"]')->attr('value');
    }

    private function day(string $modify): string
    {
        return (new \DateTimeImmutable($modify))->format('Y-m-d');
    }
}
