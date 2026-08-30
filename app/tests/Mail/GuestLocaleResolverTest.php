<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Entity\Embeddable\Address;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Mail\GuestLocaleResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GuestLocaleResolverTest extends TestCase
{
    #[DataProvider('countries')]
    public function testLocaleFollowsGuestCountry(?string $country, string $expected): void
    {
        $reservation = new Reservation(Channel::DIRECT, new \DateTimeImmutable('2026-09-01'));
        $reservation->setGuestAddress(new Address(country: $country));

        self::assertSame($expected, (new GuestLocaleResolver())->forReservation($reservation));
    }

    public function testManualChoiceBeatsCountry(): void
    {
        $reservation = new Reservation(Channel::DIRECT, new \DateTimeImmutable('2026-09-01'));
        $reservation->setGuestAddress(new Address(country: 'CZ'));
        $reservation->setGuestLocale('en');

        self::assertSame('en', (new GuestLocaleResolver())->forReservation($reservation));
    }

    public function testManualCzechForGuestWithForeignAddress(): void
    {
        $reservation = new Reservation(Channel::DIRECT, new \DateTimeImmutable('2026-09-01'));
        $reservation->setGuestAddress(new Address(country: 'DE'));
        $reservation->setGuestLocale('cs');

        self::assertSame('cs', (new GuestLocaleResolver())->forReservation($reservation));
    }

    public function testUnsupportedManualChoiceFallsBackToCountry(): void
    {
        $reservation = new Reservation(Channel::DIRECT, new \DateTimeImmutable('2026-09-01'));
        $reservation->setGuestAddress(new Address(country: 'DE'));
        $reservation->setGuestLocale('de');

        self::assertSame('en', (new GuestLocaleResolver())->forReservation($reservation));
    }

    /** @return iterable<string, array{?string, string}> */
    public static function countries(): iterable
    {
        yield 'tuzemsko' => ['CZ', 'cs'];
        yield 'Slovensko' => ['SK', 'cs'];
        yield 'Německo' => ['DE', 'en'];
        yield 'Spojené státy' => ['US', 'en'];
        yield 'malými písmeny' => ['de', 'en'];
        yield 'bez země' => [null, 'cs'];
        yield 'prázdná země' => ['', 'cs'];
    }
}
