<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Setting;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ConnectionSettingsControllerTest extends WebTestCase
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

        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('connection@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'connection@example.com']));
    }

    public function testMailboxFormSubmitsAndSaysWhenKeyIsMissing(): void
    {
        $this->client->request('GET', '/nastaveni/pripojeni');
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Uložit', [
            'mailbox_settings[imapHost]' => 'mail.priklad.cz',
            'mailbox_settings[imapPort]' => '993',
            'mailbox_settings[smtpHost]' => 'smtp.priklad.cz',
        ]);

        self::assertResponseRedirects('/nastaveni/pripojeni');

        // Testovací prostředí nemá APP_CREDENTIALS_KEY, takže se přístupy neuloží
        // a uživatel se to musí dozvědět.
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'APP_CREDENTIALS_KEY');
    }

    public function testChannelSettingsLiveElsewhere(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/pripojeni');

        self::assertCount(0, $crawler->filter('[name="mailbox_settings[bookingHotelId]"]'));
        self::assertGreaterThan(0, $crawler->filter('a[href="/nastaveni/kanaly"]')->count());
    }
}
