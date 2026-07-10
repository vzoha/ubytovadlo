<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ProfileControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private UserPasswordHasherInterface $hasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->hasher = $container->get(UserPasswordHasherInterface::class);

        $this->em->createQuery('DELETE FROM ' . User::class . ' e')->execute();

        $user = new User('profile-test@example.com');
        $user->setPassword($this->hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'profile-test@example.com']));
    }

    public function testProfilePageRenders(): void
    {
        $this->client->request('GET', '/profil');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('profile-test@example.com', (string) $this->client->getResponse()->getContent());
    }

    public function testChangePasswordSucceeds(): void
    {
        $crawler = $this->client->request('GET', '/profil');
        $form = $crawler->filter('form')->form([
            'current_password' => 'secret123',
            'new_password' => 'novehesloXY',
            'confirm_password' => 'novehesloXY',
        ]);
        $this->client->submit($form);

        self::assertResponseRedirects('/profil');
        $this->client->followRedirect();
        self::assertStringContainsString('Heslo změněno.', (string) $this->client->getResponse()->getContent());

        $user = $this->reloadUser();
        self::assertTrue($this->hasher->isPasswordValid($user, 'novehesloXY'));
    }

    public function testChangePasswordRejectsWrongCurrent(): void
    {
        $crawler = $this->client->request('GET', '/profil');
        $form = $crawler->filter('form')->form([
            'current_password' => 'spatne',
            'new_password' => 'novehesloXY',
            'confirm_password' => 'novehesloXY',
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertStringContainsString('Současné heslo nesouhlasí.', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->hasher->isPasswordValid($this->reloadUser(), 'secret123'));
    }

    public function testChangePasswordRejectsMismatchedConfirmation(): void
    {
        $crawler = $this->client->request('GET', '/profil');
        $form = $crawler->filter('form')->form([
            'current_password' => 'secret123',
            'new_password' => 'novehesloXY',
            'confirm_password' => 'jineheslo12',
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertStringContainsString('neshodují', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->hasher->isPasswordValid($this->reloadUser(), 'secret123'));
    }

    public function testChangePasswordRejectsTooShort(): void
    {
        $crawler = $this->client->request('GET', '/profil');
        $form = $crawler->filter('form')->form([
            'current_password' => 'secret123',
            'new_password' => 'krat1',
            'confirm_password' => 'krat1',
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertStringContainsString('alespoň 8 znaků', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->hasher->isPasswordValid($this->reloadUser(), 'secret123'));
    }

    private function reloadUser(): User
    {
        $this->em->clear();
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'profile-test@example.com']);
        \assert($user instanceof User);

        return $user;
    }
}
