<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Embeddable\Address;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Upozornění u rezervace ve stavu needs_details říká, co konkrétně chybí
 * a kde se to v daném kanálu bere.
 */
final class ReservationNeedsDetailsBannerTest extends WebTestCase
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
        $user = new User('banner-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'banner-test@example.com']));
    }

    private function persist(Channel $channel, ?string $guestName, ?Address $address = null): Reservation
    {
        $r = new Reservation($channel, new \DateTimeImmutable('+10 days'));
        $r->setCheckOut(new \DateTimeImmutable('+13 days'));
        $r->setStatus(ReservationStatus::NEEDS_DETAILS);
        $r->setMotopressExternalId('1765');
        if ($guestName !== null) {
            $r->setGuestName($guestName);
        }
        if ($address !== null) {
            $r->setGuestAddress($address);
        }
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    private function banner(Reservation $r): string
    {
        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        self::assertResponseIsSuccessful();

        return $crawler->filter('.alert-warning')->text();
    }

    public function testAirbnbWithGuestNamePointsToCheckin(): void
    {
        $text = $this->banner($this->persist(Channel::AIRBNB, 'Airbnb Host'));

        self::assertStringContainsString('online check-in', $text);
        self::assertStringNotContainsString('extranetu', $text);
    }

    public function testBookingWithGuestNamePointsToExtranet(): void
    {
        $text = $this->banner($this->persist(Channel::BOOKING, 'Booking Host'));

        self::assertStringContainsString('Booking extranetu', $text);
    }

    public function testMissingGuestNamePointsToExtranet(): void
    {
        $text = $this->banner($this->persist(Channel::AIRBNB, null));

        self::assertStringContainsString('blok z kalendáře', $text);
    }

    public function testKnownAddressLeavesOnlyThePlainNotice(): void
    {
        $text = $this->banner($this->persist(Channel::AIRBNB, 'Airbnb Host', new Address('Dlouhá 1', 'Praha', '11000', 'CZ')));

        self::assertStringContainsString('čeká na doplnění údajů hosta', $text);
        self::assertStringNotContainsString('check-in', $text);
    }
}
