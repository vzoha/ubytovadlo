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
use App\Entity\Embeddable\GuestContact;
use App\Entity\QuickMessage;
use App\Entity\Reservation;
use App\Entity\User;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ReservationContactLinksTest extends WebTestCase
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
        $this->em->createQuery('DELETE FROM ' . QuickMessage::class . ' q')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('contact-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'contact-test@example.com']));
    }

    private function persist(Reservation $r): Reservation
    {
        $this->em->persist($r);
        $this->em->flush();

        return $r;
    }

    private function seedQuickMessages(): void
    {
        $this->em->persist(new QuickMessage('Uvítání', 'Dobrý den {{ guest_first_name_vocative }}, těšíme se na Vás {{ check_in }}.'));
        $this->em->persist(new QuickMessage('Poděkování', 'Děkujeme za návštěvu {{ guest_first_name_vocative }}.'));
        $this->em->flush();
    }

    public function testPhoneRendersCallSmsWhatsappLinks(): void
    {
        $r = new Reservation(Channel::DIRECT, new \DateTimeImmutable('+10 days'));
        $r->setCheckOut(new \DateTimeImmutable('+13 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Kontaktní Host');
        $r->setGuestContact(new GuestContact(phone: '776 123 456'));
        $this->persist($r);

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('a[href="tel:+420776123456"]'));
        self::assertCount(1, $crawler->filter('a[href="sms:+420776123456"]'));
        self::assertCount(1, $crawler->filter('a[href="https://wa.me/420776123456"]'));
        // Telefon se zobrazí v národním tvaru.
        self::assertStringContainsString('776 123 456', (string) $this->client->getResponse()->getContent());
    }

    /**
     * Detail vypisuje adresu přes VO — Twig `?? '—'` spolkne i chybu chybějícího
     * gettru, takže rozbité napojení pozná jen assert na vypsanou hodnotu.
     */
    public function testAddressIsRenderedOnDetail(): void
    {
        $r = new Reservation(Channel::DIRECT, new \DateTimeImmutable('+10 days'));
        $r->setCheckOut(new \DateTimeImmutable('+13 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Kontaktní Host');
        $r->setGuestAddress(new Address('Dlouhá 5', 'Praha', '110 00', 'CZ'));
        $this->persist($r);

        $this->client->request('GET', '/reservation/' . $r->getId());
        self::assertResponseIsSuccessful();

        self::assertStringContainsString('Dlouhá 5, 110 00 Praha', (string) $this->client->getResponse()->getContent());
    }

    public function testQuickMessagesArePrefilledInDropdown(): void
    {
        $this->seedQuickMessages();

        $r = new Reservation(Channel::DIRECT, new \DateTimeImmutable('+10 days'));
        $r->setCheckOut(new \DateTimeImmutable('+13 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Jan Novák');
        $r->setGuestContact(new GuestContact(phone: '776 123 456'));
        $this->persist($r);

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        self::assertResponseIsSuccessful();

        // Prázdná zpráva = hlavní tlačítko (bez ?text=), rychlé zprávy jsou v dropdownu.
        self::assertCount(1, $crawler->filter('a[href="https://wa.me/420776123456"]'));

        $waPrefilled = $crawler->filter('a[href^="https://wa.me/420776123456?text="]');
        self::assertCount(2, $waPrefilled);
        // Oslovení v 5. pádu (Jan → Jane) se dosadí.
        $texts = $waPrefilled->each(static fn ($node): string => (string) $node->attr('href'));
        self::assertTrue((bool) array_filter($texts, static fn (string $h): bool => str_contains($h, 'Jane')));

        self::assertCount(2, $crawler->filter('a[href^="sms:+420776123456?body="]'));
        // Názvy rychlých zpráv v nabídce.
        self::assertStringContainsString('Uvítání', (string) $this->client->getResponse()->getContent());
    }

    public function testNoQuickMessagesLeavePlainButtons(): void
    {
        $r = new Reservation(Channel::DIRECT, new \DateTimeImmutable('+10 days'));
        $r->setCheckOut(new \DateTimeImmutable('+13 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Jan Novák');
        $r->setGuestContact(new GuestContact(phone: '776 123 456'));
        $this->persist($r);

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        self::assertResponseIsSuccessful();

        // Bez definovaných rychlých zpráv zůstanou jen prostá tlačítka, žádný předvyplněný odkaz.
        self::assertCount(0, $crawler->filter('a[href^="https://wa.me/420776123456?text="]'));
        self::assertCount(1, $crawler->filter('a[href="https://wa.me/420776123456"]'));
    }

    public function testEmailRendersMailtoLink(): void
    {
        $r = new Reservation(Channel::DIRECT, new \DateTimeImmutable('+10 days'));
        $r->setCheckOut(new \DateTimeImmutable('+13 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Kontaktní Host');
        $r->setGuestContact(new GuestContact('host@example.com'));
        $this->persist($r);

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        self::assertResponseIsSuccessful();

        self::assertGreaterThan(0, $crawler->filter('a[href="mailto:host@example.com"]')->count());
    }

    public function testNoPhoneNoDeepLinks(): void
    {
        $r = new Reservation(Channel::DIRECT, new \DateTimeImmutable('+10 days'));
        $r->setCheckOut(new \DateTimeImmutable('+13 days'));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Bez Kontaktu');
        $this->persist($r);

        $crawler = $this->client->request('GET', '/reservation/' . $r->getId());
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('a[href^="tel:"]'));
        self::assertCount(0, $crawler->filter('a[href^="https://wa.me/"]'));
    }
}
