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
use App\Profit\ReservationProfitCalculator;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class FeesSettingsControllerTest extends WebTestCase
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
        $user = new User('fees@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'fees@example.com']));
    }

    public function testFormPrefillsDefaultRate(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/poplatky');
        self::assertResponseIsSuccessful();

        self::assertSame(
            (string) ReservationProfitCalculator::RECREATION_FEE_DEFAULT,
            $crawler->filter('input[name="fees_settings[recreationFeePerAdultNight]"]')->attr('value'),
        );
    }

    public function testFormSavesRateToDatabase(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/poplatky');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit')->form();
        $form['fees_settings[recreationFeePerAdultNight]'] = '21';
        $this->client->submit($form);

        self::assertResponseRedirects('/nastaveni/poplatky');

        $settings = static::getContainer()->get(SettingRepository::class);
        self::assertSame(21, $settings->getInt(ReservationProfitCalculator::RECREATION_FEE_KEY));
    }
}
