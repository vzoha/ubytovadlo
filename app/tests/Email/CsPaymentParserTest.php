<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Email;

use App\Email\CsPaymentParser;
use App\Email\EmailMessage;
use App\Email\EmlReader;
use PHPUnit\Framework\TestCase;

final class CsPaymentParserTest extends TestCase
{
    private CsPaymentParser $parser;
    private EmlReader $reader;

    protected function setUp(): void
    {
        $this->parser = new CsPaymentParser();
        $this->reader = new EmlReader();
    }

    public function testParsesDepositNotification(): void
    {
        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/CS/prisla-platba-zaloha.eml');

        self::assertTrue($this->parser->supports($email));

        $p = $this->parser->parse($email);

        self::assertTrue($p->incoming);
        self::assertSame('1000.00', $p->amount);
        self::assertSame('CZK', $p->currency);
        self::assertSame('1234567', $p->variableSymbol);
        self::assertSame('0', $p->constantSymbol);
        self::assertSame('987654321/0100', $p->counterpartyAccount);
        self::assertSame('2026-06-16', $p->receivedAt->format('Y-m-d'));
    }

    public function testDoesNotSupportOtherSender(): void
    {
        $email = new EmailMessage(
            messageId: '<x@example.com>',
            fromAddress: 'noreply@booking.com',
            subject: 'Přišla platba',
            date: new \DateTimeImmutable(),
            textBody: '',
        );

        self::assertFalse($this->parser->supports($email));
    }

    public function testDoesNotSupportOtherSubjectFromBank(): void
    {
        $email = new EmailMessage(
            messageId: '<y@example.com>',
            fromAddress: 'ceskasporitelna@csas.cz',
            subject: 'Výpis z účtu',
            date: new \DateTimeImmutable(),
            textBody: '',
        );

        self::assertFalse($this->parser->supports($email));
    }

    public function testDetectsOutgoingPayment(): void
    {
        $email = new EmailMessage(
            messageId: '<z@example.com>',
            fromAddress: 'ceskasporitelna@csas.cz',
            subject: 'Přišla platba',
            date: new \DateTimeImmutable('2026-06-16 10:00:00'),
            textBody: 'Směr platby: odchozí Částka v měně účtu: 500,00 Kč Variabilní symbol: 99',
        );

        self::assertTrue($this->parser->supports($email));
        $p = $this->parser->parse($email);
        self::assertFalse($p->incoming);
        self::assertSame('500.00', $p->amount);
        self::assertSame('99', $p->variableSymbol);
    }
}
