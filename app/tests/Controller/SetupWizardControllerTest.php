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
use App\Enum\TaxProfile;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SetupWizardControllerTest extends WebTestCase
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
        $user = new User('wizard@example.com');
        $user->setPassword($hasher->hashPassword($user, 'secret123'));
        $user->setRoles(['ROLE_ADMIN']);
        $this->em->persist($user);
        $this->em->flush();

        $this->client->loginUser($container->get(UserRepository::class)->findOneBy(['email' => 'wizard@example.com']));
    }

    public function testStartRedirectsToFirstStep(): void
    {
        $this->client->request('GET', '/nastaveni/pruvodce');
        self::assertResponseRedirects('/nastaveni/pruvodce/instance');
    }

    public function testEachStepRenders(): void
    {
        foreach (['instance', 'dodavatel', 'pripojeni', 'mail', 'hotovo'] as $step) {
            $this->client->request('GET', '/nastaveni/pruvodce/' . $step);
            self::assertResponseIsSuccessful(sprintf('Krok %s se má vykreslit', $step));
            self::assertStringContainsString('Průvodce nastavením', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testInstanceStepSavesAndAdvances(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/pruvodce/instance');
        $form = $crawler->selectButton('Uložit a pokračovat →')->form();
        $form['general_settings[brandName]'] = 'Malý Statek';
        $this->client->submit($form);

        self::assertResponseRedirects('/nastaveni/pruvodce/dodavatel');
        $settings = static::getContainer()->get(SettingRepository::class);
        self::assertSame('Malý Statek', $settings->getString('app.brand_name'));
    }

    public function testIssuerStepSavesTaxProfileAndAdvances(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/pruvodce/dodavatel');
        $form = $crawler->selectButton('Uložit a pokračovat →')->form();
        $form['issuer_settings[name]'] = 'Malý Statek Lniště';
        $form['issuer_settings[taxProfile]'] = 'vat_payer';
        $this->client->submit($form);

        self::assertResponseRedirects('/nastaveni/pruvodce/pripojeni');
        $settings = static::getContainer()->get(SettingRepository::class);
        self::assertSame('Malý Statek Lniště', $settings->getString('invoice.issuer.name'));
        self::assertSame(TaxProfile::VAT_PAYER->value, $settings->getString('invoice.issuer.tax_profile'));
    }

    public function testUnknownStepIsNotFound(): void
    {
        $this->client->request('GET', '/nastaveni/pruvodce/neznamy');
        self::assertResponseStatusCodeSame(404);
    }
}
