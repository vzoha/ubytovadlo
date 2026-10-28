<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\CheckinDevice;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckinDeviceTest extends TestCase
{
    /** @return iterable<string, array{string, CheckinDevice}> */
    public static function userAgents(): iterable
    {
        yield 'iPhone' => ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile/15E148', CheckinDevice::MOBILE];
        yield 'Android telefon' => ['Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/120.0 Mobile Safari/537.36', CheckinDevice::MOBILE];
        yield 'iPad hlásí Mobile' => ['Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) Mobile/15E148', CheckinDevice::TABLET];
        yield 'Android tablet bez Mobile' => ['Mozilla/5.0 (Linux; Android 14; SM-X710) Chrome/120.0 Safari/537.36', CheckinDevice::TABLET];
        yield 'Windows' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36', CheckinDevice::DESKTOP];
        yield 'bez hlavičky' => ['', CheckinDevice::DESKTOP];
    }

    #[DataProvider('userAgents')]
    public function testDetectsDeviceFromUserAgent(string $userAgent, CheckinDevice $expected): void
    {
        self::assertSame($expected, CheckinDevice::fromUserAgent($userAgent));
    }
}
