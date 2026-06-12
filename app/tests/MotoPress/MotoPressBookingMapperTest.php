<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\MotoPress;

use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\MotoPress\MotoPressBookingMapper;
use PHPUnit\Framework\TestCase;

final class MotoPressBookingMapperTest extends TestCase
{
    public function testMapsConfirmedBookingFromFixture(): void
    {
        $data = $this->loadFixture('booking-confirmed.json');
        // fixture pouziva service id 42, takze v konfiguraci 925 ho nezachyti -
        // detekce psa proleti pres title fallback
        $mapper = new MotoPressBookingMapper([925]);

        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable($data['check_in_date']));
        $mapper->applyWebBooking($reservation, $data);

        self::assertSame(ReservationStatus::CONFIRMED, $reservation->getStatus());
        self::assertSame('2026-07-15', $reservation->getCheckIn()->format('Y-m-d'));
        self::assertSame('2026-07-22', $reservation->getCheckOut()?->format('Y-m-d'));
        self::assertSame('Jan Novak', $reservation->getGuestName());
        self::assertSame('jan.novak@example.cz', $reservation->getGuestEmail());
        self::assertSame('+420777123456', $reservation->getGuestPhone());
        self::assertSame('Hlavni 12', $reservation->getGuestStreet());
        self::assertSame('Praha', $reservation->getGuestCity());
        self::assertSame('110 00', $reservation->getGuestZip());
        self::assertSame(2, $reservation->getGuestsAdult());
        self::assertSame(1, $reservation->getGuestsChild());
        self::assertSame('14000.00', $reservation->getPriceTotal());
        self::assertSame('CZK', $reservation->getPriceCurrency());
        self::assertSame('Pejsek s sebou.', $reservation->getNotes());
        self::assertTrue($reservation->hasPet());
        self::assertSame('Pes', $reservation->getPetsNote());
        self::assertNotNull($reservation->getBookedAt());
        // Normalizováno do default tz (zápis == čtení v Doctrine), ale stejný instant.
        self::assertSame(
            (new \DateTimeImmutable('2026-05-01T12:23:45+00:00'))->getTimestamp(),
            $reservation->getBookedAt()->getTimestamp(),
        );
    }

    public function testDetectsPetFromTopLevelService(): void
    {
        $mapper = new MotoPressBookingMapper([925]);
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-09-01'));

        $mapper->applyWebBooking($reservation, [
            'status' => 'confirmed',
            'check_in_date' => '2026-09-01',
            'check_out_date' => '2026-09-04',
            'services' => [
                ['id' => 7, 'title' => 'Poplatek za psa', 'qty' => 1],
            ],
        ]);

        self::assertTrue($reservation->hasPet());
        self::assertSame('Poplatek za psa', $reservation->getPetsNote());
    }

    public function testFallsBackToNoteWhenNoPetService(): void
    {
        $mapper = new MotoPressBookingMapper([925]);
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-09-01'));

        $mapper->applyWebBooking($reservation, [
            'status' => 'confirmed',
            'check_in_date' => '2026-09-01',
            'check_out_date' => '2026-09-04',
            'note' => 'Vezmeme si pejska.',
        ]);

        self::assertTrue($reservation->hasPet());
        self::assertSame('Vezmeme si pejska.', $reservation->getPetsNote());
    }

    public function testNoPetWhenNotMentioned(): void
    {
        $mapper = new MotoPressBookingMapper([925]);
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-09-01'));

        $mapper->applyWebBooking($reservation, [
            'status' => 'confirmed',
            'check_in_date' => '2026-09-01',
            'check_out_date' => '2026-09-04',
            'note' => 'Prosíme o brzký check-in.',
        ]);

        self::assertFalse($reservation->hasPet());
        self::assertNull($reservation->getPetsNote());
    }

    public function testDetectsPetByServiceIdInRealFixture(): void
    {
        $data = $this->loadFixture('booking-with-pet-service.json');
        $mapper = new MotoPressBookingMapper([925]);

        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable($data['check_in_date']));
        $mapper->applyWebBooking($reservation, $data);

        self::assertTrue($reservation->hasPet());
        self::assertSame('Pes', $reservation->getPetsNote());
    }

    public function testIgnoresUnconfiguredServiceId(): void
    {
        $mapper = new MotoPressBookingMapper([999]);
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-09-01'));

        $mapper->applyWebBooking($reservation, [
            'status' => 'confirmed',
            'check_in_date' => '2026-09-01',
            'check_out_date' => '2026-09-04',
            'reserved_accommodations' => [
                ['accommodation' => 369, 'adults' => 2, 'services' => [['id' => 925, 'price' => 700]]],
            ],
        ]);

        self::assertFalse($reservation->hasPet());
    }

    public function testDetectsBabyCotByServiceId(): void
    {
        $mapper = new MotoPressBookingMapper([], [555]);
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-09-01'));

        $mapper->applyWebBooking($reservation, [
            'status' => 'confirmed',
            'check_in_date' => '2026-09-01',
            'check_out_date' => '2026-09-04',
            'reserved_accommodations' => [
                ['accommodation' => 369, 'adults' => 2, 'services' => [['id' => 555, 'price' => 0]]],
            ],
        ]);

        self::assertTrue($reservation->needsBabyCot());
    }

    public function testDetectsBabyCotFromServiceTitle(): void
    {
        $mapper = new MotoPressBookingMapper([], []);
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-09-01'));

        $mapper->applyWebBooking($reservation, [
            'status' => 'confirmed',
            'check_in_date' => '2026-09-01',
            'check_out_date' => '2026-09-04',
            'services' => [
                ['id' => 7, 'title' => 'Dětská postýlka', 'qty' => 1],
            ],
        ]);

        self::assertTrue($reservation->needsBabyCot());
    }

    public function testDetectsBabyCotFromNote(): void
    {
        $mapper = new MotoPressBookingMapper([], []);
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-09-01'));

        $mapper->applyWebBooking($reservation, [
            'status' => 'confirmed',
            'check_in_date' => '2026-09-01',
            'check_out_date' => '2026-09-04',
            'note' => 'Prosíme o postýlku pro miminko.',
        ]);

        self::assertTrue($reservation->needsBabyCot());
    }

    public function testNoBabyCotWhenNotMentioned(): void
    {
        $mapper = new MotoPressBookingMapper([], []);
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-09-01'));

        $mapper->applyWebBooking($reservation, [
            'status' => 'confirmed',
            'check_in_date' => '2026-09-01',
            'check_out_date' => '2026-09-04',
        ]);

        self::assertFalse($reservation->needsBabyCot());
    }

    public function testCancelledStatusMaps(): void
    {
        $mapper = new MotoPressBookingMapper([925]);
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-08-01'));

        $mapper->applyWebBooking($reservation, [
            'status' => 'cancelled',
            'check_in_date' => '2026-08-01',
            'check_out_date' => '2026-08-05',
        ]);

        self::assertSame(ReservationStatus::CANCELLED, $reservation->getStatus());
    }

    public function testUnknownStatusFallsBackToNeedsDetails(): void
    {
        $mapper = new MotoPressBookingMapper([925]);
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-08-01'));

        $mapper->applyWebBooking($reservation, ['status' => 'pending', 'check_in_date' => '2026-08-01']);

        self::assertSame(ReservationStatus::NEEDS_DETAILS, $reservation->getStatus());
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $path = __DIR__ . '/../Fixtures/MotoPress/' . $name;
        $content = (string) file_get_contents($path);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
