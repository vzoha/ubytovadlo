<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Entity\Invoice;
use App\Entity\InvoiceLink;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Invoice\BalanceCalculator;
use App\Invoice\DepositPaymentBuilder;
use App\Invoice\IssuedInvoiceLink;
use App\Invoice\PaymentQrLinks;
use App\Mail\GuestLocaleResolver;
use App\Mail\GuestVocative;
use App\Mail\InvoiceLinkMessage;
use App\Mail\InvoiceMessageContext;
use App\Mail\MessageVariableResolver;
use App\Repository\AccommodationProfileRepository;
use App\Repository\InvoiceRepository;
use App\Security\PublicLinkSigner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InvoiceLinkMessageTest extends TestCase
{
    private const URL = 'https://app.example.com/f/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

    public function testUnpaidInvoiceCarriesLinkAndPaymentDetails(): void
    {
        $text = $this->message()->render($this->invoice(), $this->issued());

        self::assertStringContainsString('fakturu č. 2026012', $text);
        self::assertStringContainsString(self::URL, $text);
        self::assertStringContainsString('Číslo účtu: 1861547133/0800', $text);
        self::assertStringContainsString("Odkaz platí do 27.\u{00a0}11.\u{00a0}2026", $text);
        self::assertStringNotContainsString('{{', $text);
    }

    public function testPaidInvoiceHasNoPaymentDetails(): void
    {
        $invoice = $this->invoice();
        $invoice->setPaidAt(new \DateTimeImmutable('2026-09-20'));

        $text = $this->message()->render($invoice, $this->issued());

        self::assertStringNotContainsString('Číslo účtu', $text);
        self::assertStringContainsString('uhrazeno', $text);
    }

    public function testForeignGuestGetsEnglish(): void
    {
        $invoice = $this->invoice();
        $invoice->getReservation()->setGuestLocale('en');

        $text = $this->message()->render($invoice, $this->issued());

        self::assertStringContainsString('here is invoice no. 2026012', $text);
        self::assertStringContainsString('Payment reference: 2026012', $text);
    }

    private function message(): InvoiceLinkMessage
    {
        $url = $this->createStub(UrlGeneratorInterface::class);
        $url->method('generate')->willReturn('https://app.example.com/x');
        $qrLinks = new PaymentQrLinks($url, new PublicLinkSigner('test-secret'));
        $invoiceContext = new InvoiceMessageContext($qrLinks, new GuestLocaleResolver(), $this->createStub(InvoiceRepository::class));

        $variables = new MessageVariableResolver(
            $this->createStub(AccommodationProfileRepository::class),
            $this->createStub(BalanceCalculator::class),
            $url,
            new GuestVocative(),
            $this->createStub(DepositPaymentBuilder::class),
            new GuestLocaleResolver(),
            $invoiceContext,
            $qrLinks,
        );

        return new InvoiceLinkMessage($variables, $invoiceContext, new GuestLocaleResolver());
    }

    private function invoice(): Invoice
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-09-19'));
        $reservation->setCheckOut(new \DateTimeImmutable('2026-09-22'));
        $reservation->setGuestName('Jan Novák');

        $invoice = new Invoice('2026012', 2026, 12, InvoiceType::FINAL, $reservation, new \DateTimeImmutable('2026-09-13'), new \DateTimeImmutable('2026-10-01'));
        $invoice->setTotalAmount('4200');
        $invoice->setBankAccount('1861547133/0800');

        return $invoice;
    }

    private function issued(): IssuedInvoiceLink
    {
        $link = new InvoiceLink($this->invoice(), str_repeat('0', 64), new \DateTimeImmutable('2026-09-28'), new \DateTimeImmutable('2026-11-27'));

        return new IssuedInvoiceLink($link, self::URL);
    }
}
