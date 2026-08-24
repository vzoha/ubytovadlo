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
use App\Entity\Embeddable\VatReverseCharge;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use App\Vat\CnbExchangeRateClient;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ReservationListCzkPreviewTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        // Seznam nesmí kvůli náhledu volat ČNB, když má rezervace vlastní kurz.
        $cnb = $this->createMock(CnbExchangeRateClient::class);
        $cnb->expects(self::never())->method('getRate');
        $container->set(CnbExchangeRateClient::class, $cnb);

        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->createQuery('DELETE FROM ' . Cleaning::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('czk-preview@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'czk-preview@example.com']));
    }

    public function testEurReservationShowsCzkPreviewAndCzkReservationDoesNot(): void
    {
        $eur = $this->reservation('+10 days', '100.00', 'EUR');
        $eur->setCommissionAmount('15.00')->setCommissionCurrency('EUR');
        $eur->setVatReverseCharge(new VatReverseCharge(
            cnbRate: '24.36000000',
            cnbRateDate: new \DateTimeImmutable('2026-04-16'),
        ));
        $this->reservation('+20 days', '4455.00', 'CZK');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/rezervace');

        self::assertResponseIsSuccessful();
        $prices = $crawler->filter('tbody tr td.text-end')->each(
            static fn ($td): string => preg_replace('/\s+/u', ' ', trim($td->text())) ?? '',
        );
        self::assertContains('100 € 2 436 Kč', $prices);
        self::assertContains('4 455 Kč', $prices);
    }

    private function reservation(string $checkIn, string $price, string $currency): Reservation
    {
        $r = new Reservation(Channel::BOOKING, new \DateTimeImmutable($checkIn));
        $r->setCheckOut((new \DateTimeImmutable($checkIn))->modify('+3 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Náhledový Host');
        $r->setPriceTotal($price)->setPriceCurrency($currency);
        $this->em->persist($r);

        return $r;
    }
}
