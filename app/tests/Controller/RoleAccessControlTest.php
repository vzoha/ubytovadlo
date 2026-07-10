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
use App\Enum\UserPermission;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Ověřuje matici: která role kam smí a kam ne.
 */
final class RoleAccessControlTest extends WebTestCase
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
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();
        $this->em->flush();
    }

    public function testCleanerSeesCleaningButNotOperations(): void
    {
        $this->loginAs('uklid@example.com', UserRole::CLEANER);

        $this->assertAllowed('/uklid');
        $this->assertForbidden('/rezervace');
        $this->assertForbidden('/ucty');
        $this->assertForbidden('/elektrina');
        $this->assertForbidden('/uzivatele');
        $this->assertForbidden('/nastaveni/dodavatel');
    }

    public function testCleanerWithElectricityPermission(): void
    {
        $this->loginAs('uklid2@example.com', UserRole::CLEANER, [UserPermission::ELECTRICITY]);

        $this->assertAllowed('/uklid');
        $this->assertAllowed('/elektrina');
        $this->assertForbidden('/rezervace');
    }

    public function testManagerSeesOperationsAndElectricityButNotAdmin(): void
    {
        $this->loginAs('spravce@example.com', UserRole::MANAGER);

        $this->assertAllowed('/rezervace');
        $this->assertAllowed('/ucty');
        $this->assertAllowed('/elektrina');
        $this->assertForbidden('/uzivatele');
        $this->assertForbidden('/nastaveni/dodavatel');
    }

    public function testAdminSeesEverything(): void
    {
        $this->loginAs('admin@example.com', UserRole::ADMIN);

        $this->assertAllowed('/rezervace');
        $this->assertAllowed('/elektrina');
        $this->assertAllowed('/uzivatele');
        $this->assertAllowed('/nastaveni/dodavatel');
    }

    public function testDeactivatedUserCannotLogIn(): void
    {
        $user = new User('deaktivovany@example.com');
        $user->assignAccess(UserRole::MANAGER);
        $user->setActive(false);
        $user->setPassword($this->hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/login');
        $form = $crawler->selectButton('Přihlásit')->form([
            '_username' => 'deaktivovany@example.com',
            '_password' => 'secret123',
        ]);
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertStringContainsString('deaktivovaný', (string) $this->client->getResponse()->getContent());
    }

    /** @param list<UserPermission> $permissions */
    private function loginAs(string $email, UserRole $role, array $permissions = []): void
    {
        $user = new User($email);
        $user->assignAccess($role, $permissions);
        $user->setPassword($this->hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser(static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]));
    }

    private function assertAllowed(string $uri): void
    {
        $this->client->request('GET', $uri);
        self::assertResponseIsSuccessful(sprintf('Očekáván přístup na %s', $uri));
    }

    private function assertForbidden(string $uri): void
    {
        $this->client->request('GET', $uri);
        self::assertResponseStatusCodeSame(403, sprintf('Očekáváno 403 na %s', $uri));
    }
}
