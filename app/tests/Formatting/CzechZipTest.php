<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Formatting;

use App\Formatting\CzechZip;
use PHPUnit\Framework\TestCase;

final class CzechZipTest extends TestCase
{
    public function testFormatsFiveDigits(): void
    {
        self::assertSame('370 01', CzechZip::format('37001'));
        self::assertSame('370 01', CzechZip::format('370 01'));
        self::assertSame('370 01', CzechZip::format(37001), 'ARES posílá PSČ jako číslo');
    }

    public function testEmptyIsNull(): void
    {
        self::assertNull(CzechZip::format(null));
        self::assertNull(CzechZip::format(''));
        self::assertNull(CzechZip::format('   '));
    }

    /** Zahraniční PSČ do české šablony nepatří — nech ho tak, jak dorazilo. */
    public function testForeignZipIsKeptAsIs(): void
    {
        self::assertSame('SW1A 1AA', CzechZip::format('SW1A 1AA'));
        self::assertSame('1234', CzechZip::format('1234'));
    }
}
