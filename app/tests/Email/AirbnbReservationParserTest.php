<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Email;

use App\Email\AirbnbReservationParser;
use App\Email\EmlReader;
use PHPUnit\Framework\TestCase;

final class AirbnbReservationParserTest extends TestCase
{
    private AirbnbReservationParser $parser;
    private EmlReader $reader;

    protected function setUp(): void
    {
        $this->parser = new AirbnbReservationParser('Vejminek');
        $this->reader = new EmlReader();
    }

    public function testParsesRichardFranzReservation(): void
    {
        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/Airbnb/rezervace-potvrzena-petr-novak-pijede-3-9.eml');

        self::assertTrue($this->parser->supports($email));

        $r = $this->parser->parse($email);

        self::assertSame('HMABCD12EF', $r->confirmationCode);
        self::assertSame('Petr Novák', $r->guestName);
        self::assertSame('Jihočeský kraj, Česko', $r->guestRegion);

        self::assertSame('2026-09-03', $r->checkIn->format('Y-m-d'));
        self::assertSame('2026-09-09', $r->checkOut->format('Y-m-d'));
        self::assertSame('16:00', $r->checkInTime?->format('H:i'));
        self::assertSame('11:00', $r->checkOutTime?->format('H:i'));

        self::assertSame(4, $r->guestsAdult);
        self::assertSame(0, $r->guestsChild);
        self::assertSame(0, $r->guestsInfant);

        self::assertSame(2000.0, $r->pricePerNight);
        self::assertSame(6, $r->nights);
        self::assertSame(14000.0, $r->priceTotal);
        self::assertSame(360.0, $r->hostCommission);
        self::assertSame(11640.0, $r->netPayout);
        self::assertFalse($r->hasPet);
        self::assertNull($r->petsNote);
    }

    public function testDetectsPetFromText(): void
    {
        $reflection = new \ReflectionClass(AirbnbReservationParser::class);
        $method = $reflection->getMethod('detectPet');
        $method->setAccessible(true);

        [$has, $note] = $method->invoke($this->parser, 'Hosté 2 dospělí 1 domácí mazlíček');
        self::assertTrue($has);
        self::assertSame('1 domácí mazlíček', $note);

        [$has, $note] = $method->invoke($this->parser, 'Guests 2 adults 1 pet');
        self::assertTrue($has);
        self::assertSame('1 pet', $note);

        [$has, $note] = $method->invoke($this->parser, 'Asistenční zvíře přítomno.');
        self::assertTrue($has);
        self::assertSame('Asistenční zvíře', $note);

        [$has, $note] = $method->invoke($this->parser, 'Hosté 4 dospělí.');
        self::assertFalse($has);
        self::assertNull($note);
    }

    public function testParsesVeronikaReservation(): void
    {
        $email = $this->reader->fromFile(__DIR__ . '/../Fixtures/Airbnb/rezervace-potvrzena-jana-svobodova-pijede-6-5.eml');

        self::assertTrue($this->parser->supports($email));

        $r = $this->parser->parse($email);

        self::assertSame('HMGHIJ34KL', $r->confirmationCode);
        self::assertSame('Jana Svobodová', $r->guestName);

        self::assertSame('05-06', $r->checkIn->format('m-d'));
        self::assertSame('05-10', $r->checkOut->format('m-d'));
        self::assertSame('16:00', $r->checkInTime?->format('H:i'));
        self::assertSame('11:00', $r->checkOutTime?->format('H:i'));

        self::assertSame(3, $r->guestsAdult);
        self::assertSame(0, $r->guestsChild);
        self::assertSame(0, $r->guestsInfant);

        self::assertSame(1500.0, $r->pricePerNight);
        self::assertSame(4, $r->nights);
        self::assertSame(7000.0, $r->priceTotal);
        self::assertSame(180.0, $r->hostCommission);
        self::assertSame(5820.0, $r->netPayout);
    }

    public function testRejectsNonAirbnbEmail(): void
    {
        $email = new \App\Email\EmailMessage(
            messageId: 'foo@bar',
            fromAddress: 'noreply@booking.com',
            subject: 'Booking.com - Nová rezervace! (7000000001, pondělí 15. dubna 2026)',
            date: new \DateTimeImmutable('2026-01-25'),
            textBody: 'whatever',
        );

        self::assertFalse($this->parser->supports($email));
    }
}
