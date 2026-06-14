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
use App\Invoice\IssuerProfileProvider;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class IssuerSettingsControllerTest extends WebTestCase
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
        $user = new User('issuer@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'issuer@example.com']));
    }

    public function testFormSavesIssuerToDatabase(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/dodavatel');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit')->form();
        $form['issuer_settings[name]'] = 'Malý Statek Lniště';
        $form['issuer_settings[ico]'] = '87654321';
        $form['issuer_settings[bankAccountIban]'] = 'CZ6508000000001234567890';
        $this->client->submit($form);

        self::assertResponseRedirects('/nastaveni/dodavatel');

        $settings = static::getContainer()->get(SettingRepository::class);
        self::assertSame('Malý Statek Lniště', $settings->getString('invoice.issuer.name'));

        // a provider to promítne do dodavatele faktury
        $issuer = static::getContainer()->get(IssuerProfileProvider::class)->current();
        self::assertSame('Malý Statek Lniště', $issuer->name);
        self::assertSame('87654321', $issuer->ico);
    }
}
