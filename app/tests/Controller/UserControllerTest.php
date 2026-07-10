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
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserControllerTest extends WebTestCase
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
        $this->createUser('admin@example.com', UserRole::ADMIN);
        $this->em->flush();

        $this->client->loginUser($this->repo()->findOneBy(['email' => 'admin@example.com']));
    }

    public function testIndexListsUsers(): void
    {
        $this->client->request('GET', '/uzivatele');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('admin@example.com', (string) $this->client->getResponse()->getContent());
    }

    public function testCreateUser(): void
    {
        $this->post('/uzivatele/novy', 'user-create', [
            'email' => 'spravce@example.com',
            'password' => 'tajneheslo1',
            'role' => UserRole::MANAGER->value,
        ]);

        self::assertResponseRedirects('/uzivatele');
        $user = $this->repo()->findOneBy(['email' => 'spravce@example.com']);
        self::assertNotNull($user);
        self::assertSame(UserRole::MANAGER, $user->getRole());
    }

    public function testCreateRejectsShortPassword(): void
    {
        $this->post('/uzivatele/novy', 'user-create', [
            'email' => 'kratke@example.com',
            'password' => 'krat',
            'role' => UserRole::MANAGER->value,
        ]);

        self::assertNull($this->repo()->findOneBy(['email' => 'kratke@example.com']));
    }

    public function testChangeRole(): void
    {
        $cleaner = $this->createUser('uklid@example.com', UserRole::CLEANER);
        $this->em->flush();
        $id = $cleaner->getId();

        $this->post('/uzivatele/' . $id, 'user-update-' . $id, [
            'role' => UserRole::MANAGER->value,
            'active' => '1',
        ]);

        self::assertResponseRedirects('/uzivatele');
        $this->em->clear();
        $reloaded = $this->repo()->find($id);
        self::assertNotNull($reloaded);
        self::assertSame(UserRole::MANAGER, $reloaded->getRole());
    }

    public function testCannotDemoteLastAdmin(): void
    {
        $admin = $this->repo()->findOneBy(['email' => 'admin@example.com']);
        $id = $admin->getId();

        $this->post('/uzivatele/' . $id, 'user-update-' . $id, [
            'role' => UserRole::MANAGER->value,
            'active' => '1',
        ]);

        $this->em->clear();
        self::assertSame(UserRole::ADMIN, $this->repo()->find($id)->getRole());
    }

    public function testCannotDeleteSelf(): void
    {
        $admin = $this->repo()->findOneBy(['email' => 'admin@example.com']);
        $id = $admin->getId();

        $this->post('/uzivatele/' . $id . '/smazat', 'user-delete-' . $id, []);

        self::assertNotNull($this->repo()->find($id));
    }

    public function testDeleteUser(): void
    {
        $victim = $this->createUser('smazat@example.com', UserRole::MANAGER);
        $this->em->flush();
        $id = $victim->getId();

        $this->post('/uzivatele/' . $id . '/smazat', 'user-delete-' . $id, []);

        $this->em->clear();
        self::assertNull($this->repo()->find($id));
    }

    private function createUser(string $email, UserRole $role): User
    {
        $user = new User($email);
        $user->setRole($role);
        $user->setPassword($this->hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);

        return $user;
    }

    /**
     * Vytáhne CSRF token příslušného formuláře ze stránky /uzivatele a odešle POST.
     *
     * @param array<string, string|list<string>> $params
     */
    private function post(string $uri, string $tokenId, array $params): void
    {
        $crawler = $this->client->request('GET', '/uzivatele');
        $selector = $tokenId === 'user-create'
            ? 'form[action$="/novy"] input[name="_token"]'
            : 'form[action$="' . $uri . '"] input[name="_token"]';
        $value = $crawler->filter($selector)->attr('value');

        $this->client->request('POST', $uri, $params + ['_token' => $value]);
    }

    private function repo(): UserRepository
    {
        return static::getContainer()->get(UserRepository::class);
    }
}
