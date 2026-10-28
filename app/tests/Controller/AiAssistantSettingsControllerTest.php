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
use App\Mcp\McpAccessToken;
use App\Mcp\Tool\PropertyInfoTool;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AiAssistantSettingsControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $em->createQuery('DELETE FROM ' . Setting::class . ' s')->execute();
        $em->createQuery('DELETE FROM ' . User::class . ' u')->execute();

        $user = new User('ai@example.com');
        $user->setPassword('x');
        $user->setRoles(['ROLE_ADMIN']);
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'ai@example.com']));
    }

    public function testPropertyInfoIsSaved(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/ai-asistent');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Uložit')->form(['ai_assistant_settings[propertyInfo]' => '  Parkování u domu.  ']);
        $this->client->submit($form);

        self::assertResponseRedirects('/nastaveni/ai-asistent');
        self::assertSame('Parkování u domu.', static::getContainer()->get(SettingRepository::class)->getString(PropertyInfoTool::SETTING_KEY));
    }

    public function testIssuedTokenIsShownOnceAndOnlyHashIsStored(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/ai-asistent');
        $this->client->submit($crawler->selectButton('Vydat přístup')->form());

        self::assertResponseRedirects('/nastaveni/ai-asistent');
        $this->client->followRedirect();
        $token = (string) $this->client->getCrawler()->filter('#mcp-token')->attr('value');
        self::assertStringStartsWith('ubyt_', $token);
        self::assertTrue(static::getContainer()->get(McpAccessToken::class)->matches($token));
        self::assertNotSame($token, static::getContainer()->get(SettingRepository::class)->getString(McpAccessToken::SETTING_KEY));

        $this->client->request('GET', '/nastaveni/ai-asistent');
        self::assertSelectorNotExists('#mcp-token');
        self::assertTrue(static::getContainer()->get(McpAccessToken::class)->matches($token), 'Obnovení stránky nesmí vydat nový token.');
        self::assertSelectorTextContains('button.btn-outline-danger', 'Zrušit přístup');
    }

    public function testRevokeDisablesAccess(): void
    {
        $token = static::getContainer()->get(McpAccessToken::class)->issue();

        $crawler = $this->client->request('GET', '/nastaveni/ai-asistent');
        $this->client->submit($crawler->selectButton('Zrušit přístup')->form());

        self::assertResponseRedirects('/nastaveni/ai-asistent');
        self::assertFalse(static::getContainer()->get(McpAccessToken::class)->matches($token));
    }

    public function testIssueWithoutCsrfDoesNothing(): void
    {
        $this->client->request('POST', '/nastaveni/ai-asistent/pristup', ['_token' => 'bad']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse(static::getContainer()->get(McpAccessToken::class)->isIssued());
    }
}
