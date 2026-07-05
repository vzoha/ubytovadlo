<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Connector\ConnectorManager;
use App\Entity\Connector;
use App\Entity\User;
use App\Enum\ConnectorType;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ConnectorControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);

        $this->em->createQuery('DELETE FROM ' . Connector::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('connector@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'connector@example.com']));
    }

    public function testTogglingFromSettingsPageDisablesConnector(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/pripojeni');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[action*="konektory/motopress/prepnout"]')->form();
        $this->client->submit($form);

        self::assertResponseRedirects('/nastaveni/pripojeni');

        $manager = static::getContainer()->get(ConnectorManager::class);
        self::assertFalse($manager->isEnabled(ConnectorType::MOTOPRESS));
    }

    public function testUnknownConnectorTypeIsNotFound(): void
    {
        $this->client->request('POST', '/nastaveni/konektory/neexistuje/prepnout', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }
}
