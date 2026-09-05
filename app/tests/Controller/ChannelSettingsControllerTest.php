<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Connector;
use App\Entity\Setting;
use App\Entity\User;
use App\Enum\ConnectorType;
use App\MotoPress\MotoPressSettings;
use App\Repository\ConnectorRepository;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Kanály a platby: vypisují se jen používaná napojení, zbytek se přidává
 * tlačítkem a nastavení každého se ukládá vlastním formulářem. Banka patří
 * mezi platby, portály mezi prodejní kanály.
 */
final class ChannelSettingsControllerTest extends WebTestCase
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

        $this->em->createQuery('DELETE FROM ' . Connector::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('channels@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'channels@example.com']));
    }

    public function testPaymentSourceIsListedApartFromSalesChannels(): void
    {
        $this->enable(ConnectorType::BANK_CS);
        $this->enable(ConnectorType::BOOKING);

        $crawler = $this->client->request('GET', '/nastaveni/kanaly');
        self::assertResponseIsSuccessful();

        $groups = $crawler->filter('h3.text-muted')->each(static fn ($node) => $node->text());
        self::assertSame(['Prodejní kanály', 'Platby'], $groups);
    }

    public function testOwnCalendarIsOfferedAboveTheChannelsAndInsideThem(): void
    {
        $this->enable(ConnectorType::BOOKING);

        $crawler = $this->client->request('GET', '/nastaveni/kanaly');
        self::assertResponseIsSuccessful();

        // Vlastní kalendář je jeden pro všechny portály, proto stojí nad seznamem…
        self::assertCount(1, $crawler->filter('#ical-feed-url'));
        // …a v kartě kanálu je po ruce jen tlačítko, ať se nemíchá s feedem portálu.
        $card = $crawler->filter('.card')->reduce(
            static fn ($node) => str_contains($node->text(), 'Booking.com'),
        )->first();
        self::assertStringContainsString('Kalendář portálu', $card->text());
        self::assertGreaterThan(0, $card->filter('[data-copy="#ical-feed-url"]')->count());
    }

    public function testUnusedChannelsAreOfferedInsteadOfListed(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/kanaly');
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('.card-header .fw-semibold'), 'Bez zapnutého kanálu se žádná karta nevypisuje');
        self::assertCount(
            \count(ConnectorType::cases()),
            $crawler->filter('.dropdown-menu form'),
            'Všechny kanály čekají v nabídce k přidání',
        );
    }

    public function testAddedChannelGetsItsOwnCard(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/kanaly');
        $form = $crawler->filter('form[action="/nastaveni/kanaly/booking/pridat"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/nastaveni/kanaly');
        $crawler = $this->client->followRedirect();

        $headers = $crawler->filter('.card-header')->each(static fn ($node) => $node->text());
        self::assertNotEmpty(array_filter($headers, static fn (string $t): bool => str_contains($t, 'Booking.com')));

        $connector = static::getContainer()->get(ConnectorRepository::class)->findOneBy(['type' => ConnectorType::BOOKING]);
        self::assertNotNull($connector);
        self::assertTrue($connector->isEnabled());
    }

    public function testBookingCardSavesHotelId(): void
    {
        $this->enable(ConnectorType::BOOKING);

        $crawler = $this->client->request('GET', '/nastaveni/kanaly');
        $this->client->submit($crawler->filter('form[action="/nastaveni/kanaly/booking"]')->form([
            'booking_channel[bookingHotelId]' => 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/booking.html?hotel_id=1234567',
        ]));

        self::assertResponseRedirects('/nastaveni/kanaly');
        self::assertSame('1234567', static::getContainer()->get(SettingRepository::class)->getString('booking.hotel_id'));
    }

    public function testMotoPressCardSavesServiceMapping(): void
    {
        $this->enable(ConnectorType::MOTOPRESS);

        $crawler = $this->client->request('GET', '/nastaveni/kanaly');
        $this->client->submit($crawler->filter('form[action="/nastaveni/kanaly/motopress"]')->form([
            'moto_press_channel[petServiceIds]' => '925, 926',
            'moto_press_channel[babyCotServiceIds]' => '866',
            'moto_press_channel[pushPayments]' => '1',
        ]));

        self::assertResponseRedirects('/nastaveni/kanaly');

        $settings = static::getContainer()->get(SettingRepository::class);
        self::assertSame('925,926', $settings->getString(MotoPressSettings::KEY_PET));
        self::assertSame('866', $settings->getString(MotoPressSettings::KEY_BABY_COT));
        self::assertSame('1', $settings->getString(MotoPressSettings::KEY_PUSH));
    }

    private function enable(ConnectorType $type): void
    {
        $this->em->persist(new Connector($type));
        $this->em->flush();
    }
}
