<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\GuestDocument;
use App\Entity\Reservation;
use App\Enum\Channel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Volba jazyka na online check-inu: přepínač (?_locale), autodetekce z
 * Accept-Language, paměť v session a fallback na češtinu.
 */
final class CheckinLocaleTest extends WebTestCase
{
    private KernelBrowser $client;
    private Reservation $reservation;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        $em->createQuery('DELETE FROM ' . GuestDocument::class . ' g')->execute();
        $em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $em->flush();

        $this->reservation = new Reservation(Channel::BOOKING, new \DateTimeImmutable('2026-06-15'));
        $this->reservation->setCheckOut(new \DateTimeImmutable('2026-06-18'));
        $this->reservation->setGuestsAdult(2);
        $em->persist($this->reservation);
        $em->flush();
    }

    private function url(string $suffix = ''): string
    {
        return '/checkin/' . $this->reservation->getCheckinToken() . $suffix;
    }

    public function testQueryParamSwitchesToEnglish(): void
    {
        $this->client->request('GET', $this->url('?_locale=en'));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="en"]');
        self::assertSelectorTextContains('body', 'Guests');
    }

    public function testQueryParamSwitchesToGerman(): void
    {
        $this->client->request('GET', $this->url('?_locale=de'));

        self::assertSelectorExists('html[lang="de"]');
        self::assertSelectorTextContains('body', 'Gäste');
    }

    public function testAcceptLanguageAutodetectsPolish(): void
    {
        $this->client->request('GET', $this->url(), [], [], ['HTTP_ACCEPT_LANGUAGE' => 'pl,en;q=0.8']);

        self::assertSelectorExists('html[lang="pl"]');
        self::assertSelectorTextContains('body', 'Goście');
    }

    public function testQueryParamSwitchesToFrench(): void
    {
        $this->client->request('GET', $this->url('?_locale=fr'));

        self::assertSelectorExists('html[lang="fr"]');
        self::assertSelectorTextContains('body', 'Hôtes');
    }

    public function testQueryParamSwitchesToItalian(): void
    {
        $this->client->request('GET', $this->url('?_locale=it'));

        self::assertSelectorExists('html[lang="it"]');
        self::assertSelectorTextContains('body', 'Ospiti');
    }

    public function testInvalidLocaleFallsThroughToAutodetect(): void
    {
        // Neznámý ?_locale se ignoruje a rozhodne prohlížeč (Accept-Language).
        $this->client->request('GET', $this->url('?_locale=xx'), [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de']);

        self::assertSelectorExists('html[lang="de"]');
        self::assertSelectorTextContains('body', 'Gäste');
    }

    public function testFallsBackToCzechWithoutAnySignal(): void
    {
        // Prohlížeč bez Accept-Language → výchozí čeština.
        $this->client->request('GET', $this->url(), [], [], ['HTTP_ACCEPT_LANGUAGE' => '']);

        self::assertSelectorExists('html[lang="cs"]');
        self::assertSelectorTextContains('body', 'Hosté');
    }

    public function testHostFormRendersInGerman(): void
    {
        // Nejbohatší stránka: sken dokladu (JS překlady), výběr země, form pole.
        $this->client->request('GET', $this->url('/host/novy?_locale=de'));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="de"]');
        self::assertSelectorTextContains('h1', 'Gast hinzufügen');
    }

    public function testBillingFormRendersInPolish(): void
    {
        $this->client->request('GET', $this->url('/fakturace?_locale=pl'));

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="pl"]');
        self::assertSelectorTextContains('h1', 'faktury');
    }

    public function testSessionRemembersChoiceAcrossRequests(): void
    {
        // Přepnu na němčinu; další návštěva zůstane německy, i když prohlížeč
        // hlásí angličtinu (výchozí Accept-Language testovacího klienta).
        $this->client->request('GET', $this->url('?_locale=de'));
        $this->client->request('GET', $this->url());

        self::assertSelectorExists('html[lang="de"]');
        self::assertSelectorTextContains('body', 'Gäste');
    }
}
