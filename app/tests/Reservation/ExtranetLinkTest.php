<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Reservation;

use App\Entity\Reservation;
use App\Enum\Channel;
use App\Repository\SettingRepository;
use App\Reservation\ExtranetLink;
use PHPUnit\Framework\TestCase;

final class ExtranetLinkTest extends TestCase
{
    public function testAirbnbLinkNeedsOnlyConfirmationCode(): void
    {
        $link = $this->link(hotelId: null)->forReservation($this->reservation(Channel::AIRBNB, 'HMABCD12EF'));

        self::assertSame('https://www.airbnb.com/hosting/reservations/details/HMABCD12EF', $link);
    }

    public function testBookingLinkCarriesReservationAndProperty(): void
    {
        $link = $this->link('10718270')->forReservation($this->reservation(Channel::BOOKING, '7000000001'));

        self::assertSame(
            'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/booking.html?res_id=7000000001&hotel_id=10718270&lang=cs',
            $link,
        );
    }

    public function testBookingWithoutPropertyIdHasNoLink(): void
    {
        self::assertNull($this->link(hotelId: null)->forReservation($this->reservation(Channel::BOOKING, '7000000001')));
    }

    public function testAirbnbWithoutConfirmationCodeHasNoLink(): void
    {
        // iCal blok nese jen pořadové ID — stránka rezervace v extranetu k němu nevede.
        self::assertNull($this->link(hotelId: null)->forReservation($this->reservation(Channel::AIRBNB, '120')));
    }

    public function testChannelsWithoutExtranetHaveNoLink(): void
    {
        self::assertNull($this->link('10718270')->forReservation($this->reservation(Channel::WEB, 'MP-1')));
        self::assertNull($this->link('10718270')->forReservation($this->reservation(Channel::AIRBNB, null)));
    }

    private function link(?string $hotelId): ExtranetLink
    {
        $settings = $this->createStub(SettingRepository::class);
        $settings->method('getString')->willReturn($hotelId);

        return new ExtranetLink($settings);
    }

    private function reservation(Channel $channel, ?string $externalId): Reservation
    {
        $reservation = new Reservation($channel, new \DateTimeImmutable('2026-05-10'));
        $reservation->setExternalId($externalId);

        return $reservation;
    }
}
