<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Customer\CustomerKey;
use App\Entity\Embeddable\GuestContact;
use PHPUnit\Framework\TestCase;

final class CustomerKeyTest extends TestCase
{
    public function testEmailIsLowercased(): void
    {
        $key = CustomerKey::fromContact(new GuestContact('Jana.Host@Example.com'));

        self::assertSame('jana.host@example.com', $key->email);
    }

    public function testPortalAddressIsNotAKey(): void
    {
        $key = CustomerKey::fromContact(new GuestContact('abc123@guest.booking.com'));

        self::assertNull($key->email);
        self::assertTrue($key->isEmpty());
    }

    public function testParsablePhoneIsKeyInE164(): void
    {
        $key = CustomerKey::fromContact(new GuestContact(phone: '776 123 456'));

        self::assertSame('+420776123456', $key->phone);
    }

    public function testUnparsablePhoneIsNotAKey(): void
    {
        $key = CustomerKey::fromContact(new GuestContact(phone: 'volat po 18h'));

        self::assertNull($key->phone);
        self::assertTrue($key->isEmpty());
    }

    public function testEmptyContactGivesEmptyKey(): void
    {
        self::assertTrue(CustomerKey::fromContact(new GuestContact())->isEmpty());
    }

    public function testInvalidNumberInInternationalFormIsNotAKey(): void
    {
        $key = CustomerKey::fromContact(new GuestContact(phone: '+999 123'));

        self::assertNull($key->phone);
    }
}
