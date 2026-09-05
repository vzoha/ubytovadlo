<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Booking;

use App\Booking\BookingHotelId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingHotelIdTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function hotelIdProvider(): array
    {
        return [
            'samotné číslo' => ['10718270', '10718270'],
            'vlepená adresa' => ['https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/booking.html?res_id=7000000001&hotel_id=10718270&lang=cs', '10718270'],
            'adresa zalomená e-mailem' => ["   https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/booking.h\n   tml?res_id=7000000001&hotel_id=107182\n   70&lang=cs\n", '10718270'],
            'text bez ID' => ['Booking.com', null],
            'prázdno' => ['', null],
        ];
    }

    #[DataProvider('hotelIdProvider')]
    public function testNormalizes(string $raw, ?string $expected): void
    {
        self::assertSame($expected, BookingHotelId::normalize($raw));
    }
}
