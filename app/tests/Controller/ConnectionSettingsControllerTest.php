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
use App\MotoPress\MotoPressSettings;
use App\Repository\SettingRepository;
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

    public function testFormSavesMotopressMappingSettings(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/pripojeni');
        self::assertResponseIsSuccessful();

        // Chování MotoPressu je součástí formuláře připojení; jeho hlavní tlačítko
        // (btn-primary) je jednoznačné, konektory mají vlastní „Uložit feed".
        $form = $crawler->filter('button.btn-primary')->form();
        $this->client->submit($form, [
            'connection_settings[petServiceIds]' => '925, 926',
            'connection_settings[babyCotServiceIds]' => '866',
            'connection_settings[pushPayments]' => '1',
        ]);

        self::assertResponseRedirects('/nastaveni/pripojeni');

        $settings = static::getContainer()->get(SettingRepository::class);
        self::assertSame('925,926', $settings->getString(MotoPressSettings::KEY_PET));
        self::assertSame('866', $settings->getString(MotoPressSettings::KEY_BABY_COT));
        self::assertSame('1', $settings->getString(MotoPressSettings::KEY_PUSH));
    }
}
