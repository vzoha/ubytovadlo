<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\ValueObject;

use App\ValueObject\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function czVariantsProvider(): iterable
    {
        yield 'národní se spacem' => ['776 123 456', '+420776123456'];
        yield 'národní bez spacu' => ['776123456', '+420776123456'];
        yield 'pomlčky' => ['776-123-456', '+420776123456'];
        yield 'předvolba s +' => ['+420 776 123 456', '+420776123456'];
        yield 'předvolba 00' => ['00420776123456', '+420776123456'];
        yield 'závorky a text' => ['tel: (776) 123 456', '+420776123456'];
    }

    #[DataProvider('czVariantsProvider')]
    public function testNormalizesCzVariantsToE164(string $raw, string $expected): void
    {
        self::assertSame($expected, PhoneNumber::fromString($raw)->e164());
    }

    public function testKeepsForeignPrefix(): void
    {
        self::assertSame('+491701234567', PhoneNumber::fromString('+49 170 1234567')->e164());
    }

    public function testFormatters(): void
    {
        $phone = PhoneNumber::fromString('776 123 456');

        self::assertSame('776 123 456', $phone->national());
        self::assertSame('+420 776 123 456', $phone->international());
        self::assertSame('420776123456', $phone->whatsapp());
        self::assertSame('+420776123456', (string) $phone);
    }

    public function testRejectsTooShortNumber(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PhoneNumber::fromString('123');
    }

    public function testRejectsGarbage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        PhoneNumber::fromString('vůbec ne číslo');
    }

    public function testTryFromReturnsNullForEmpty(): void
    {
        self::assertNull(PhoneNumber::tryFromString(null));
        self::assertNull(PhoneNumber::tryFromString(''));
        self::assertNull(PhoneNumber::tryFromString('   '));
    }

    public function testTryFromReturnsNullForInvalid(): void
    {
        self::assertNull(PhoneNumber::tryFromString('123'));
    }

    public function testTryFromParsesValid(): void
    {
        self::assertSame('+420776123456', (string) PhoneNumber::tryFromString('776 123 456'));
    }
}
