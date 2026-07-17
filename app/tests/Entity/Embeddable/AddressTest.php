<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Entity\Embeddable;

use App\Entity\Embeddable\Address;
use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase
{
    public function testEmptyAddress(): void
    {
        $address = new Address();

        self::assertTrue($address->isEmpty());
        self::assertNull($address->format());
        self::assertNull($address->getStreet());
    }

    public function testBlanksNormalizeToNull(): void
    {
        $address = new Address(street: '  ', city: ' Praha ', zip: '', country: '  ');

        self::assertNull($address->getStreet());
        self::assertNull($address->getZip());
        self::assertSame('Praha', $address->getCity());
        self::assertNull($address->getCountry());
    }

    public function testCountryIsUppercased(): void
    {
        self::assertSame('CZ', (new Address(country: ' cz '))->getCountry());
    }

    /** Země sama o sobě adresu netvoří — nemá co tisknout na fakturu. */
    public function testCountryAloneIsEmpty(): void
    {
        self::assertTrue((new Address(country: 'CZ'))->isEmpty());
    }

    public function testFormatJoinsStreetWithZipAndCity(): void
    {
        $address = new Address('Dlouhá 5', 'Praha', '110 00', 'CZ');

        self::assertSame('Dlouhá 5, 110 00 Praha', $address->format());
    }

    public function testFormatSkipsMissingParts(): void
    {
        self::assertSame('Praha', (new Address(city: 'Praha'))->format());
        self::assertSame('Dlouhá 5', (new Address(street: 'Dlouhá 5'))->format());
    }

    public function testWithersLeaveOriginalUntouched(): void
    {
        $original = new Address('Dlouhá 5', 'Praha', '110 00', 'CZ');
        $moved = $original->withCity('Brno');

        self::assertSame('Praha', $original->getCity());
        self::assertSame('Brno', $moved->getCity());
        self::assertSame('Dlouhá 5', $moved->getStreet());
    }

    public function testEquals(): void
    {
        $address = new Address('Dlouhá 5', 'Praha', '110 00', 'CZ');

        self::assertTrue($address->equals(new Address('Dlouhá 5', 'Praha', '110 00', 'CZ')));
        self::assertTrue($address->equals(new Address('Dlouhá 5', 'Praha', '110 00', 'cz')));
        self::assertFalse($address->equals($address->withZip('602 00')));
    }
}
