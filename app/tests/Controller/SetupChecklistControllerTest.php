<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Credential;
use App\Entity\Setting;
use App\Entity\User;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SetupChecklistControllerTest extends WebTestCase
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
        $this->em->createQuery('DELETE FROM ' . Credential::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('checklist@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'checklist@example.com']));
    }

    public function testDismissHidesItemFromDashboard(): void
    {
        $crawler = $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Automatizační schránka', $crawler->html());

        $form = $crawler->filter('form[action="/checklist/imap/skryt"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/');

        $settings = static::getContainer()->get(SettingRepository::class);
        self::assertSame('1', $settings->getString('setup.dismissed.imap'));

        $crawler = $this->client->request('GET', '/');
        self::assertStringNotContainsString('Automatizační schránka', $crawler->html());
        self::assertStringContainsString('Zobrazit skryté', $crawler->html());
    }

    public function testRestoreBringsHiddenItemsBack(): void
    {
        static::getContainer()->get(SettingRepository::class)->set('setup.dismissed.imap', '1');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/');
        $form = $crawler->filter('form[action="/checklist/obnovit"]')->form();
        $this->client->submit($form);
        self::assertResponseRedirects('/');

        $crawler = $this->client->request('GET', '/');
        self::assertStringContainsString('Automatizační schránka', $crawler->html());
    }

    public function testDismissRejectsInvalidCsrfToken(): void
    {
        $this->client->request('POST', '/checklist/imap/skryt', ['_token' => 'spatny']);
        self::assertResponseRedirects('/');

        $settings = static::getContainer()->get(SettingRepository::class);
        self::assertNull($settings->getString('setup.dismissed.imap'));
    }
}
