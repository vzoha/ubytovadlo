<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Entity\AccommodationProfile;
use App\Entity\Embeddable\PropertyAddress;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Invoice\BalanceCalculator;
use App\Invoice\BalanceResult;
use App\Invoice\DepositPayment;
use App\Invoice\DepositPaymentBuilder;
use App\Mail\GuestLocaleResolver;
use App\Mail\GuestVocative;
use App\Mail\InvoiceMessageContext;
use App\Mail\MessageVariableResolver;
use App\Repository\AccommodationProfileRepository;
use App\Repository\InvoiceRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class MessageVariableResolverTest extends TestCase
{
    public function testSubstitutesKnownPlaceholdersAndKeepsUnknown(): void
    {
        $resolver = $this->resolver(null);
        $reservation = $this->reservation();

        $out = $resolver->render(
            'Ahoj {{ guest_first_name }}, příjezd {{ check_in }}, {{ nights }} nocí. {{ neznama }}',
            $reservation,
        );

        self::assertSame("Ahoj Jan, příjezd 13.\u{00a0}4.\u{00a0}2026, 3 nocí. {{ neznama }}", $out);
    }

    public function testFirstNameVocativeDeclinesGreeting(): void
    {
        $resolver = $this->resolver(null);

        $out = $resolver->render('Dobrý den, {{ guest_first_name_vocative }},', $this->reservation());

        self::assertSame('Dobrý den, Jane,', $out);
    }

    public function testContextOverridesValue(): void
    {
        $resolver = $this->resolver(null);

        $out = $resolver->render('Faktura {{ invoice_number }}', $this->reservation(), ['invoice_number' => '2026012']);

        self::assertSame('Faktura 2026012', $out);
    }

    public function testBalanceDueRendersWhenAvailable(): void
    {
        $resolver = $this->resolver(new BalanceResult(6000.0, 1000.0, 5000.0));

        $out = $resolver->render('Doplatek {{ balance_due }}', $this->reservation());

        self::assertStringContainsString('5', $out);
        self::assertStringContainsString('Kč', $out);
    }

    public function testDepositVariablesRenderFromDeposit(): void
    {
        $deposit = new DepositPayment(
            '1000.00',
            '1760',
            '1861547133/0800',
            'CZ6508000000001861547133',
            new \DateTimeImmutable('2026-07-20'),
            'SPD*1.0*ACC:CZ6508000000001861547133*AM:1000.00*CC:CZK*X-VS:1760',
        );
        $resolver = $this->resolver(null, $deposit);

        $out = $resolver->render(
            'Záloha {{ deposit_amount }}, VS {{ variable_symbol }}, účet {{ bank_account }}, do {{ deposit_due }}',
            $this->reservation(),
        );

        self::assertStringContainsString('VS 1760', $out);
        self::assertStringContainsString('účet 1861547133/0800', $out);
        self::assertStringContainsString("do 20.\u{00a0}7.\u{00a0}2026", $out);
        self::assertStringContainsString('1', $out); // částka
        self::assertStringContainsString('Kč', $out);
    }

    public function testDepositQrEmptyWithoutDeposit(): void
    {
        $resolver = $this->resolver(null, null);

        self::assertSame('', $resolver->renderBody('QR: {{ deposit_qr }}', $this->reservation()));
    }

    /** Řádek, na kterém zůstaly jen prázdné proměnné, se do zprávy nedostane. */
    public function testDropsLineWithOnlyEmptyVariables(): void
    {
        $resolver = $this->resolver(null, null);

        $out = $resolver->renderBody(
            "Dobrý den,\n\nSplatnost: {{ deposit_due }}\nNocí: {{ nights }}\n\nDěkujeme.",
            $this->reservation(),
        );

        self::assertSame("Dobrý den,\n\nNocí: 3\n\nDěkujeme.", $out);
    }

    /** Řádek s aspoň jednou vyplněnou proměnnou zůstává celý. */
    public function testKeepsLineWithAtLeastOneFilledVariable(): void
    {
        $resolver = $this->resolver(null, null);

        self::assertSame('3 nocí, záloha ', $resolver->renderBody('{{ nights }} nocí, záloha {{ deposit_amount }}', $this->reservation()));
    }

    public function testDepositQrRendersMarkdownImageForPersistedReservation(): void
    {
        $deposit = new DepositPayment(
            '1000.00',
            '1760',
            '1861547133/0800',
            'CZ6508000000001861547133',
            new \DateTimeImmutable('2026-07-20'),
            'SPD*1.0*ACC:CZ6508000000001861547133*AM:1000.00*CC:CZK*X-VS:1760',
        );
        $resolver = $this->resolver(null, $deposit);

        $reservation = $this->reservation();
        $reservation->setCheckinToken(str_repeat('0123456789abcdef', 4));
        (new \ReflectionProperty(Reservation::class, 'id'))->setValue($reservation, 42);

        $out = $resolver->render('{{ deposit_qr }}', $reservation);

        self::assertStringContainsString('![QR platba zálohy](https://example.test/checkin/abc)', $out);
    }

    public function testAddressUsesVillagePartWhenThereIsNoStreet(): void
    {
        $profile = (new AccommodationProfile())
            ->setNazev('Vejminek')
            ->setAddress(new PropertyAddress(obec: 'Slavče', castObce: 'Lniště', cp: '30', psc: '37401'));

        $out = $this->resolver(null, profile: $profile)->render('{{ accommodation_address }}', $this->reservation());

        self::assertSame('Lniště 30, 374 01 Slavče', $out);
    }

    public function testAddressKeepsStreetAndVillagePartApart(): void
    {
        $profile = (new AccommodationProfile())
            ->setNazev('Apartmán')
            ->setAddress(new PropertyAddress(obec: 'Brno', castObce: 'Žabovřesky', ulice: 'Horova', cp: '12', co: '3', psc: '61600'));

        $out = $this->resolver(null, profile: $profile)->render('{{ accommodation_address }}', $this->reservation());

        self::assertSame('Horova 12/3, Žabovřesky, 616 00 Brno', $out);
    }

    /** Čeština má tři tvary slova noc, text je musí trefit. */
    public function testNightsWordUsesCzechPlural(): void
    {
        $resolver = $this->resolver(null, null);

        self::assertSame("3\u{00a0}noci", $resolver->render('{{ nights_word }}', $this->reservation()));
        self::assertSame("1\u{00a0}noc", $resolver->render('{{ nights_word }}', $this->reservation(1)));
        self::assertSame("5\u{00a0}nocí", $resolver->render('{{ nights_word }}', $this->reservation(5)));
    }

    public function testNightsWordFollowsGuestLanguage(): void
    {
        $resolver = $this->resolver(null, null);
        $reservation = $this->reservation();
        $reservation->setGuestAddress($reservation->getGuestAddress()->withCountry('DE'));

        self::assertSame("3\u{00a0}nights", $resolver->render('{{ nights_word }}', $reservation));
    }

    /** Paleta v UI nabízí každou proměnnou právě jednou. */
    public function testGroupsCoverEveryVariableExactlyOnce(): void
    {
        $names = [];
        foreach (MessageVariableResolver::groupedVariables() as $group => $variables) {
            self::assertNotEmpty($variables, sprintf('Sekce %s je prázdná.', $group));
            $names = array_merge($names, array_keys($variables));
        }

        self::assertSame($names, array_unique($names));
        self::assertSame(array_keys(MessageVariableResolver::variables()), $names);
    }

    /** Prostý text (chat portálu, SMS) obrázky neunese. */
    public function testPlainTextGroupsDropImageVariables(): void
    {
        $names = array_merge(...array_map('array_keys', array_values(MessageVariableResolver::plainTextGroupedVariables())));

        self::assertNotContains('deposit_qr', $names);
        self::assertNotContains('invoice_qr', $names);
        self::assertContains('invoice_total', $names);
    }

    private function resolver(?BalanceResult $balance, ?DepositPayment $deposit = null, ?AccommodationProfile $profile = null): MessageVariableResolver
    {
        $profiles = $this->createStub(AccommodationProfileRepository::class);
        $profiles->method('getSingleton')->willReturn($profile);

        $calc = $this->createStub(BalanceCalculator::class);
        $calc->method('forReservation')->willReturn($balance);

        $url = $this->createStub(UrlGeneratorInterface::class);
        $url->method('generate')->willReturn('https://example.test/checkin/abc');

        $deposits = $this->createStub(DepositPaymentBuilder::class);
        $deposits->method('forReservation')->willReturn($deposit);

        $invoiceContext = new InvoiceMessageContext($url, new GuestLocaleResolver(), $this->createStub(InvoiceRepository::class));

        return new MessageVariableResolver(
            $profiles,
            $calc,
            $url,
            new GuestVocative(),
            $deposits,
            new GuestLocaleResolver(),
            $invoiceContext,
        );
    }

    private function reservation(int $nights = 3): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-04-13'));
        $r->setCheckOut((new \DateTimeImmutable('2026-04-13'))->modify(sprintf('+%d days', $nights)));
        $r->setGuestName('Jan Novák');
        $r->setPriceTotal('6000');
        $r->setExternalId('1760');

        return $r;
    }
}
