<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Ubyport;

use App\Entity\AccommodationProfile;
use App\Entity\GuestDocument;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\PurposeOfStay;
use App\Ubyport\UnlExporter;
use PHPUnit\Framework\TestCase;

class UnlExporterTest extends TestCase
{
    private UnlExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new UnlExporter();
    }

    public function testHeaderMatchesUbyportFormat(): void
    {
        $result = $this->exporter->build(
            $this->buildProfile(),
            [],
            new \DateTimeImmutable('2015-12-06 04:31:26'),
        );

        $utf8 = iconv('WINDOWS-1250', 'UTF-8', $result->content);
        $lines = explode("\r\n", rtrim($utf8, "\r\n"));

        self::assertCount(1, $lines, 'jen hlavicka kdyz nejsou hosti');
        self::assertSame(
            'A|2|123456789012|VODPO|Hotel Pošta|Jan Sibelius, tel: 261 197 135|Strakonice|Vodňany|Vodňany I|Alešova|26||38901|2015.12.06 04:31:26||',
            $lines[0],
        );
    }

    public function testGuestLineMatchesUbyportFormat(): void
    {
        $reservation = $this->buildReservation(
            Channel::BOOKING,
            new \DateTimeImmutable('2015-10-01'),
            new \DateTimeImmutable('2015-10-02'),
            PurposeOfStay::OBCHODNI,
        );
        $doc = new GuestDocument($reservation, 'Fazul', 'Abdalla', new \DateTimeImmutable('1974-02-25'));
        $doc->setNationalityCode('XXX');
        $doc->setPermanentResidenceAbroad('Kábul, Kedale 21');
        $doc->setDocumentNumber('321654');
        $doc->setVisaNumber('999');

        $result = $this->exporter->build(
            $this->buildProfile(),
            [$doc],
            new \DateTimeImmutable('2015-12-06 04:31:26'),
        );

        $utf8 = iconv('WINDOWS-1250', 'UTF-8', $result->content);
        $lines = explode("\r\n", rtrim($utf8, "\r\n"));

        self::assertCount(2, $lines);
        self::assertSame(
            'U|01.10.2015|02.10.2015|ABDALLA|FAZUL||25.02.1974|||XXX|Kábul, Kedale 21|321654|999|01||',
            $lines[1],
        );
    }

    public function testContentIsWindows1250Encoded(): void
    {
        $result = $this->exporter->build($this->buildProfile(), [], new \DateTimeImmutable('2026-05-19 10:00:00'));

        $sBytes = unpack('C*', iconv('UTF-8', 'WINDOWS-1250', 'š'))[1];
        self::assertSame(0x9A, $sBytes, 'sanity: š v Windows-1250 je 0x9A');
        self::assertStringContainsString(chr(0x9A), $result->content, 'soubor obsahuje Windows-1250 š');
        self::assertStringNotContainsString("\u{0161}", $result->content, 'soubor neobsahuje UTF-8 š');
    }

    public function testFilenameUsesIdubAndTimestamp(): void
    {
        $result = $this->exporter->build($this->buildProfile(), [], new \DateTimeImmutable('2026-05-19 14:23:00'));

        self::assertSame('123456789012_2605191423.unl', $result->filename);
    }

    public function testReservationWithoutCheckOutThrows(): void
    {
        $r = new Reservation(Channel::BOOKING, new \DateTimeImmutable('2026-04-01'));
        $doc = new GuestDocument($r, 'Test', 'Test', new \DateTimeImmutable('1990-01-01'));
        $doc->setNationalityCode('DEU');
        $doc->setDocumentNumber('AB123');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('check-out');
        $this->exporter->build($this->buildProfile(), [$doc], new \DateTimeImmutable());
    }

    public function testMissingNationalityThrows(): void
    {
        $r = $this->buildReservation(Channel::BOOKING, new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-03'), PurposeOfStay::TURISTIKA);
        $doc = new GuestDocument($r, 'Test', 'Test', new \DateTimeImmutable('1990-01-01'));
        $doc->setDocumentNumber('AB123');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('občanství');
        $this->exporter->build($this->buildProfile(), [$doc], new \DateTimeImmutable());
    }

    public function testMissingDocumentNumberThrows(): void
    {
        $r = $this->buildReservation(Channel::BOOKING, new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-03'), PurposeOfStay::TURISTIKA);
        $doc = new GuestDocument($r, 'Test', 'Test', new \DateTimeImmutable('1990-01-01'));
        $doc->setNationalityCode('DEU');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('číslo dokladu');
        $this->exporter->build($this->buildProfile(), [$doc], new \DateTimeImmutable());
    }

    public function testMultilineResidenceCollapsesToSingleLine(): void
    {
        $r = $this->buildReservation(Channel::BOOKING, new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-03'), PurposeOfStay::TURISTIKA);
        $doc = new GuestDocument($r, 'Smith', 'John', new \DateTimeImmutable('1990-01-01'));
        $doc->setNationalityCode('GBR');
        $doc->setDocumentNumber('GB123');
        $doc->setPermanentResidenceAbroad("Londyn\nBaker Street 221B");

        $result = $this->exporter->build($this->buildProfile(), [$doc], new \DateTimeImmutable('2026-05-19 10:00:00'));
        $utf8 = iconv('WINDOWS-1250', 'UTF-8', $result->content);

        self::assertStringContainsString('|Londyn Baker Street 221B|', $utf8);
        self::assertStringNotContainsString("Londyn\nBaker", $utf8, 'novy radek by rozbil format UNL');
    }

    public function testGuestCountReflectsDocuments(): void
    {
        $r = $this->buildReservation(Channel::AIRBNB, new \DateTimeImmutable('2026-04-01'), new \DateTimeImmutable('2026-04-03'), PurposeOfStay::TURISTIKA);
        $a = new GuestDocument($r, 'A', 'A', new \DateTimeImmutable('1980-01-01'));
        $a->setNationalityCode('SVK');
        $a->setDocumentNumber('A1');
        $b = new GuestDocument($r, 'B', 'B', new \DateTimeImmutable('1985-01-01'));
        $b->setNationalityCode('DEU');
        $b->setDocumentNumber('B2');
        $docs = [$a, $b];

        $result = $this->exporter->build($this->buildProfile(), $docs, new \DateTimeImmutable('2026-05-19'));

        self::assertSame(2, $result->guestCount);
    }

    private function buildProfile(): AccommodationProfile
    {
        $p = new AccommodationProfile();
        $p->setIdub('123456789012');
        $p->setKod('VODPO');
        $p->setNazev('Hotel Pošta');
        $p->setSpojeni('Jan Sibelius, tel: 261 197 135');
        $p->setOkres('Strakonice');
        $p->setObec('Vodňany');
        $p->setCastObce('Vodňany I');
        $p->setUlice('Alešova');
        $p->setCp('26');
        $p->setCo(null);
        $p->setPsc('38901');

        return $p;
    }

    private function buildReservation(
        Channel $channel,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        PurposeOfStay $purpose,
    ): Reservation {
        $r = new Reservation($channel, $from);
        $r->setCheckOut($to);
        $r->setUbyportPurposeOfStay($purpose);

        return $r;
    }
}
