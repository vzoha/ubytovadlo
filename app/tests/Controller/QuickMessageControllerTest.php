<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\QuickMessage;
use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\QuickMessageRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class QuickMessageControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private QuickMessageRepository $messages;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->messages = $container->get(QuickMessageRepository::class);

        $this->em->createQuery('DELETE FROM ' . QuickMessage::class . ' q')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('qm-test@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->setRole(UserRole::ADMIN);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'qm-test@example.com']));
    }

    private function seed(string $label, string $body, int $sortOrder): QuickMessage
    {
        $message = new QuickMessage($label, $body);
        $message->setSortOrder($sortOrder);
        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    public function testCreate(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/rychle-zpravy/nova');
        $this->client->submitForm('Uložit', [
            'quick_message[label]' => 'Kód dveří',
            'quick_message[body]' => 'Kód dveří je 1234.',
        ]);

        self::assertResponseRedirects('/nastaveni/rychle-zpravy');
        $saved = $this->messages->findOneBy(['label' => 'Kód dveří']);
        self::assertNotNull($saved);
        self::assertSame('Kód dveří je 1234.', $saved->getBody());
    }

    public function testEdit(): void
    {
        $message = $this->seed('Uvítání', 'Ahoj', 0);

        $this->client->request('GET', '/nastaveni/rychle-zpravy/' . $message->getId());
        $this->client->submitForm('Uložit', [
            'quick_message[label]' => 'Uvítání hosta',
            'quick_message[body]' => 'Dobrý den, těšíme se.',
        ]);
        self::assertResponseRedirects('/nastaveni/rychle-zpravy');

        $this->em->clear();
        $reloaded = $this->messages->find($message->getId());
        self::assertNotNull($reloaded);
        self::assertSame('Uvítání hosta', $reloaded->getLabel());
        self::assertSame('Dobrý den, těšíme se.', $reloaded->getBody());
    }

    public function testDelete(): void
    {
        $message = $this->seed('Smazat mě', 'text', 0);
        $id = $message->getId();

        $crawler = $this->client->request('GET', '/nastaveni/rychle-zpravy');
        $token = (string) $crawler->filter('form[action$="/' . $id . '/smazat"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/nastaveni/rychle-zpravy/' . $id . '/smazat', ['_token' => $token]);
        self::assertResponseRedirects('/nastaveni/rychle-zpravy');

        $this->em->clear();
        self::assertNull($this->messages->find($id));
    }

    public function testMoveDownSwapsOrder(): void
    {
        $first = $this->seed('První', 'a', 0);
        $second = $this->seed('Druhá', 'b', 1);

        $crawler = $this->client->request('GET', '/nastaveni/rychle-zpravy');
        $token = (string) $crawler->filter('form[action$="/' . $first->getId() . '/posun"] input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/nastaveni/rychle-zpravy/' . $first->getId() . '/posun', [
            '_token' => $token,
            'direction' => 'down',
        ]);
        self::assertResponseRedirects('/nastaveni/rychle-zpravy');

        $this->em->clear();
        $ordered = $this->messages->findOrdered();
        self::assertSame('Druhá', $ordered[0]->getLabel());
        self::assertSame('První', $ordered[1]->getLabel());
    }
}
