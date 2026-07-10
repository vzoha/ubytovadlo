<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\MessageTemplate;
use App\Entity\User;
use App\Enum\MessageKind;
use App\Repository\MessageTemplateRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class MessageTemplatesControllerTest extends WebTestCase
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

        $this->em->createQuery('DELETE FROM ' . MessageTemplate::class . ' t')->execute();
        $this->em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $user = new User('mail@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'mail@example.com']));
    }

    public function testIndexListsAllKinds(): void
    {
        $this->client->request('GET', '/nastaveni/zpravy');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Před příjezdem');
        self::assertSelectorTextContains('body', 'Faktura e-mailem');
    }

    public function testEditSavesOverrideToDatabase(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/zpravy/pre_arrival');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit')->form();
        $form['message_template[subject]'] = 'Vítejte u nás';
        $form['message_template[bodyMarkdown]'] = 'Dobrý den {{ guest_first_name }}.';
        $this->client->submit($form);

        self::assertResponseRedirects('/nastaveni/zpravy/pre_arrival');

        $template = static::getContainer()->get(MessageTemplateRepository::class)->findByKind(MessageKind::PRE_ARRIVAL);
        self::assertNotNull($template);
        self::assertSame('Vítejte u nás', $template->getSubject());
    }

    public function testPreviewRendersSampleData(): void
    {
        $this->client->request('POST', '/nastaveni/zpravy/pre_arrival/nahled', [
            'subject' => 'Předmět',
            'body' => 'Ahoj {{ guest_first_name }}!',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ahoj Jan!', (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownKindReturns404(): void
    {
        $this->client->request('GET', '/nastaveni/zpravy/neexistuje');

        self::assertResponseStatusCodeSame(404);
    }
}
