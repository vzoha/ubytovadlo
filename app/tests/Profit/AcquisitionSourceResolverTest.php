<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Profit;

use App\Entity\Reservation;
use App\Enum\Channel;
use App\Profit\AcquisitionSourceResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AcquisitionSourceResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{Channel, ?string, string, string}>
     */
    public static function sources(): iterable
    {
        yield 'volný text drží popisek' => [Channel::WEB, 'Google', 'google', 'Google'];
        yield 'velikost písmen a mezery sjednotí' => [Channel::WEB, '  doporučení ', 'doporučení', 'Doporučení'];
        yield 'hodnota kanálu dostane jeho popisek' => [Channel::WEB, 'E-chalupy', 'echalupy', 'eChalupy'];
        yield 'zkrácený název kanálu' => [Channel::BOOKING, 'Booking', 'booking', 'Booking.com'];
        yield 'plný název kanálu' => [Channel::BOOKING, 'booking.com', 'booking', 'Booking.com'];
        yield 'portál bez zdroje = portál' => [Channel::AIRBNB, null, 'airbnb', 'Airbnb'];
        yield 'iCal portál bez zdroje = portál' => [Channel::ECHALUPY, '', 'echalupy', 'eChalupy'];
        yield 'web bez zdroje = neuvedeno' => [Channel::WEB, null, '', 'Neuvedeno'];
        yield 'přímá s „Neznámo" = neuvedeno' => [Channel::DIRECT, 'Neznámo', '', 'Neuvedeno'];
    }

    #[DataProvider('sources')]
    public function testResolve(Channel $channel, ?string $source, string $key, string $label): void
    {
        $reservation = new Reservation($channel, new \DateTimeImmutable('2026-07-01'));
        $reservation->setAcquisitionSource($source);

        self::assertSame(['key' => $key, 'label' => $label], AcquisitionSourceResolver::resolve($reservation));
    }
}
