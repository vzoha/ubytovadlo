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
use App\Entity\QuickMessage;
use App\Entity\Setting;
use App\Entity\User;
use App\Enum\TaxProfile;
use App\Repository\AccommodationProfileRepository;
use App\Repository\QuickMessageRepository;
use App\Repository\SettingRepository;
use App\Repository\UserRepository;
use App\Setup\SetupChecklist;
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

        $this->em->createQuery('DELETE FROM ' . QuickMessage::class . ' q')->execute();
        $this->em->createQuery('DELETE FROM ' . AccommodationProfile::class . ' a')->execute();
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
        foreach (['instance', 'dodavatel', 'ubytovani', 'pripojeni', 'mail', 'hotovo'] as $step) {
            $this->client->request('GET', '/nastaveni/pruvodce/' . $step);
            self::assertResponseIsSuccessful(sprintf('Krok %s se má vykreslit', $step));
            self::assertStringContainsString('Průvodce nastavením', (string) $this->client->getResponse()->getContent());
        }
    }

    public function testStepsLinkBackToPrevious(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/pruvodce/dodavatel');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(
            0,
            $crawler->filter('a[href="/nastaveni/pruvodce/instance"]')->count(),
            'Krok má odkazovat zpět na předchozí',
        );
    }

    public function testStepperMarksOnlyReallyCompletedSteps(): void
    {
        // Nic není nastaveno → z pozdějšího kroku není žádný krok odškrtnutý jako hotový.
        $crawler = $this->client->request('GET', '/nastaveni/pruvodce/mail');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('ol a.text-success'), 'Bez vyplnění není žádný krok hotový');

        // Vyplníme identitu instance…
        $form = $this->client->request('GET', '/nastaveni/pruvodce/instance')
            ->selectButton('Uložit a pokračovat →')->form();
        $form['general_settings[brandName]'] = 'Test';
        $this->client->submit($form);

        // …a z pozdějšího kroku je „Aplikace" hotová, ostatní pořád ne.
        $crawler = $this->client->request('GET', '/nastaveni/pruvodce/mail');
        self::assertCount(
            1,
            $crawler->filter('ol a.text-success[href="/nastaveni/pruvodce/instance"]'),
            'Vyplněný krok se odškrtne',
        );
        self::assertCount(1, $crawler->filter('ol a.text-success'), 'Nevyplněné kroky zůstávají neodškrtnuté');
    }

    public function testMailStepShowsLivePreview(): void
    {
        $this->client->request('GET', '/nastaveni/pruvodce/mail');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('#mail-preview', 'Krok e-maily má obsahovat živý náhled');
    }

    public function testConnectionStepListsConnectorStatus(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/pruvodce/pripojeni');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Aktuální stav', (string) $this->client->getResponse()->getContent());
        self::assertGreaterThan(0, $crawler->filter('.badge')->count(), 'Má vypsat stav konektorů');
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

        self::assertResponseRedirects('/nastaveni/pruvodce/ubytovani');
        $settings = static::getContainer()->get(SettingRepository::class);
        self::assertSame('Malý Statek Lniště', $settings->getString('invoice.issuer.name'));
        self::assertSame(TaxProfile::VAT_PAYER->value, $settings->getString('invoice.issuer.tax_profile'));
    }

    public function testAccommodationStepSavesProfileAndAdvances(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/pruvodce/ubytovani');
        $form = $crawler->selectButton('Uložit a pokračovat →')->form();
        $form['accommodation_profile[idub]'] = '100000000001';
        $form['accommodation_profile[kod]'] = 'UBYT1';
        $form['accommodation_profile[nazev]'] = 'Apartmán U Lesa';
        $form['accommodation_profile[spojeni]'] = 'Jana Hostinská, tel: 000 000 000';
        $form['accommodation_profile[okres]'] = 'Jihočeský';
        $form['accommodation_profile[obec]'] = 'Lhota';
        $form['accommodation_profile[psc]'] = '38901';
        $this->client->submit($form);

        self::assertResponseRedirects('/nastaveni/pruvodce/pripojeni');

        $profile = static::getContainer()->get(AccommodationProfileRepository::class)->getSingleton();
        self::assertNotNull($profile, 'Krok má založit profil ubytovacího zařízení');
        self::assertSame('Apartmán U Lesa', $profile->getNazev());
    }

    public function testConnectionStepSendsUserBackToWizard(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/pruvodce/pripojeni');

        self::assertGreaterThan(
            0,
            $crawler->filter('a[href="/nastaveni/pripojeni?pruvodce=1"]')->count(),
            'Odkaz na přístupy nese značku průvodce, aby se uživatel vrátil zpět',
        );
    }

    public function testFinishClosesWizardAndGoesToDashboard(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/pruvodce/hotovo');
        $this->client->submit($crawler->selectButton('Hotovo — na přehled')->form());

        self::assertResponseRedirects('/');
        self::assertTrue(
            static::getContainer()->get(SetupChecklist::class)->wizardCompleted(),
            'Uzavřením se průvodce označí za dokončený',
        );
    }

    public function testMailStepShowsBookingSecurityGuideWithOwnValues(): void
    {
        $settings = static::getContainer()->get(SettingRepository::class);
        $settings->set('app.base_url', 'https://app.priklad.cz', 'Test.');
        $settings->set('mail.sender.email', 'info@priklad.cz', 'Test.');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/nastaveni/pruvodce/mail');
        $guide = $crawler->filter('.card')->reduce(
            static fn ($node) => str_contains($node->text(), 'Zprávy hostům z Booking.com'),
        )->first();

        self::assertStringContainsString('info@priklad.cz', $guide->text());
        self::assertStringContainsString('app.priklad.cz', $guide->text());
    }

    public function testFinishSeedsDefaultQuickMessages(): void
    {
        $crawler = $this->client->request('GET', '/nastaveni/pruvodce/hotovo');
        $this->client->submit($crawler->selectButton('Hotovo — na přehled')->form());

        self::assertNotEmpty(
            static::getContainer()->get(QuickMessageRepository::class)->findOrdered(),
            'Dokončená instance má připravené rychlé zprávy',
        );
    }

    public function testUnknownStepIsNotFound(): void
    {
        $this->client->request('GET', '/nastaveni/pruvodce/neznamy');
        self::assertResponseStatusCodeSame(404);
    }
}
