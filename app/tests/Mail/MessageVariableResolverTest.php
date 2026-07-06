<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Entity\Reservation;
use App\Enum\Channel;
use App\Invoice\BalanceCalculator;
use App\Invoice\BalanceResult;
use App\Invoice\DepositPayment;
use App\Invoice\DepositPaymentBuilder;
use App\Mail\GuestVocative;
use App\Mail\MessageVariableResolver;
use App\Repository\AccommodationProfileRepository;
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

        self::assertSame('Ahoj Jan, příjezd 13. 4. 2026, 3 nocí. {{ neznama }}', $out);
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
        self::assertStringContainsString('do 20. 7. 2026', $out);
        self::assertStringContainsString('1', $out); // částka
        self::assertStringContainsString('Kč', $out);
    }

    public function testDepositQrEmptyWithoutDeposit(): void
    {
        $resolver = $this->resolver(null, null);

        self::assertSame('QR: ', $resolver->render('QR: {{ deposit_qr }}', $this->reservation()));
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

    private function resolver(?BalanceResult $balance, ?DepositPayment $deposit = null): MessageVariableResolver
    {
        $profiles = $this->createStub(AccommodationProfileRepository::class);
        $profiles->method('getSingleton')->willReturn(null);

        $calc = $this->createStub(BalanceCalculator::class);
        $calc->method('forReservation')->willReturn($balance);

        $url = $this->createStub(UrlGeneratorInterface::class);
        $url->method('generate')->willReturn('https://example.test/checkin/abc');

        $deposits = $this->createStub(DepositPaymentBuilder::class);
        $deposits->method('forReservation')->willReturn($deposit);

        return new MessageVariableResolver($profiles, $calc, $url, new GuestVocative(), $deposits);
    }

    private function reservation(): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-04-13'));
        $r->setCheckOut(new \DateTimeImmutable('2026-04-16'));
        $r->setGuestName('Jan Novák');
        $r->setPriceTotal('6000');
        $r->setExternalId('1760');

        return $r;
    }
}
