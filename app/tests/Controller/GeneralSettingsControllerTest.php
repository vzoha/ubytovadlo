<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Config\InstanceSettings;
use App\Entity\Setting;
use App\Entity\User;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class GeneralSettingsControllerTest extends WebTestCase
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
        $user = new User('general@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'general@example.com']));
    }

    public function testFormSavesInstanceSettingsToDatabase(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/obecne');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit')->form();
        $form['general_settings[brandName]'] = 'Penzion U Lesa';
        $form['general_settings[baseUrl]'] = 'https://app.penzionulesa.cz';
        $this->client->submit($form);

        self::assertResponseRedirects('/nastaveni/obecne');

        $settings = static::getContainer()->get(SettingRepository::class);
        self::assertSame('Penzion U Lesa', $settings->getString(InstanceSettings::KEY_BRAND_NAME));
        self::assertSame('https://app.penzionulesa.cz', $settings->getString(InstanceSettings::KEY_BASE_URL));

        $instance = static::getContainer()->get(InstanceSettings::class);
        self::assertSame('Penzion U Lesa', $instance->brandName());
    }

    public function testPageOffersLogoUpload(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/obecne');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('input[type="file"][name="general_settings[logoFile]"]'));
        self::assertStringContainsString('multipart/form-data', (string) $crawler->filter('form')->first()->attr('enctype'));
    }
}
