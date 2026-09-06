<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Mail\MessageLocales;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageLocalesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function interfaceLocales(): iterable
    {
        yield 'čeština zůstává češtinou' => ['cs', 'cs'];
        yield 'slovenštině píšeme česky' => ['sk', 'cs'];
        yield 'němčina → angličtina' => ['de', 'en'];
        yield 'polština → angličtina' => ['pl', 'en'];
        yield 'angličtina zůstává angličtinou' => ['en', 'en'];
    }

    #[DataProvider('interfaceLocales')]
    public function testMapsInterfaceLocaleToMessageLocale(string $interface, string $expected): void
    {
        self::assertSame($expected, MessageLocales::fromInterfaceLocale($interface));
    }
}
