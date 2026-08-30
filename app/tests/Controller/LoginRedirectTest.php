<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AccommodationProfile;
use App\Entity\Setting;
use App\Entity\User;
use App\Enum\UserRole;
use App\Setup\SetupChecklist;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Kam přihlášení vede: nedonastavená instance posílá správce do průvodce,
 * jinak na první stránku podle role.
 */
final class LoginRedirectTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;

        $this->em->createQuery('DELETE FROM ' . AccommodationProfile::class . ' a')->execute();
        $this->em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();
    }

    public function testAdminOnFreshInstanceLandsInWizard(): void
    {
        $this->createUser('admin@example.com', UserRole::ADMIN);

        $this->login('admin@example.com');

        self::assertResponseRedirects('/nastaveni/pruvodce');
    }

    public function testClosedWizardLandsOnReservations(): void
    {
        $this->createUser('admin@example.com', UserRole::ADMIN);
        static::getContainer()->get(SetupChecklist::class)->completeWizard();

        $this->login('admin@example.com');

        self::assertResponseRedirects('/rezervace');
    }

    public function testCleanerNeverGetsTheWizard(): void
    {
        $this->createUser('uklid@example.com', UserRole::CLEANER);

        $this->login('uklid@example.com');

        self::assertResponseRedirects('/uklid');
    }

    private function createUser(string $email, UserRole $role): void
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user = new User($email);
        $user->setRole($role);
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();
    }

    private function login(string $email): void
    {
        $crawler = $this->client->request('GET', '/login');
        $this->client->submit($crawler->selectButton('Přihlásit')->form([
            '_username' => $email,
            '_password' => 'secret123',
        ]));
    }
}
