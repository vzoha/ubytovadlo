<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Formatting;

use App\Formatting\CountryNames;
use PHPUnit\Framework\TestCase;

final class CountryNamesTest extends TestCase
{
    public function testKnownCode(): void
    {
        self::assertSame('Německo', CountryNames::czech('DE'));
    }

    public function testCaseInsensitive(): void
    {
        self::assertSame('Rakousko', CountryNames::czech('at'));
    }

    public function testUnknownCodeReturnedUppercased(): void
    {
        self::assertSame('JP', CountryNames::czech('jp'));
    }
}
