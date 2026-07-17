<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Entity\Embeddable;

use App\Entity\Embeddable\GuestContact;
use PHPUnit\Framework\TestCase;

final class GuestContactTest extends TestCase
{
    public function testEmptyContact(): void
    {
        $contact = new GuestContact();

        self::assertTrue($contact->isEmpty());
        self::assertFalse($contact->hasEmail());
        self::assertNull($contact->getPhone());
    }

    public function testBlanksNormalizeToNull(): void
    {
        $contact = new GuestContact(email: '  ', phone: '');

        self::assertNull($contact->getEmail());
        self::assertNull($contact->getPhone());
        self::assertTrue($contact->isEmpty());
    }

    public function testEmailIsTrimmed(): void
    {
        self::assertSame('host@example.com', (new GuestContact(' host@example.com '))->getEmail());
    }

    public function testParsablePhoneIsStoredAsE164(): void
    {
        self::assertSame('+420776123456', (new GuestContact(phone: '776 123 456'))->getPhone());
    }

    /** OTA posílají proxy čísla i nesmysly — co nejde naparsovat, uložíme tak, jak přišlo. */
    public function testUnparsablePhoneIsKeptAsIs(): void
    {
        self::assertSame('volat pres Airbnb', (new GuestContact(phone: ' volat pres Airbnb '))->getPhone());
    }

    public function testHasEmail(): void
    {
        self::assertTrue((new GuestContact('host@example.com'))->hasEmail());
        self::assertFalse((new GuestContact(phone: '776 123 456'))->hasEmail());
    }

    public function testWithersLeaveOriginalUntouched(): void
    {
        $original = new GuestContact('host@example.com', '776 123 456');
        $changed = $original->withEmail('jiny@example.com');

        self::assertSame('host@example.com', $original->getEmail());
        self::assertSame('jiny@example.com', $changed->getEmail());
        self::assertSame('+420776123456', $changed->getPhone());
    }

    public function testEquals(): void
    {
        $contact = new GuestContact('host@example.com', '+420776123456');

        self::assertTrue($contact->equals(new GuestContact('host@example.com', '776 123 456')));
        self::assertFalse($contact->equals($contact->withEmail(null)));
    }
}
