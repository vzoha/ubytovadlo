<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\Channel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChannelTest extends TestCase
{
    #[DataProvider('otaCases')]
    public function testIsOta(Channel $channel, bool $expected): void
    {
        self::assertSame($expected, $channel->isOta());
    }

    /**
     * @return iterable<string, array{Channel, bool}>
     */
    public static function otaCases(): iterable
    {
        yield 'Booking je provizní OTA' => [Channel::BOOKING, true];
        yield 'Airbnb je provizní OTA' => [Channel::AIRBNB, true];
        yield 'web není OTA' => [Channel::WEB, false];
        yield 'přímá rezervace není OTA' => [Channel::DIRECT, false];
        yield 'eChalupy je jen iCal obsazenost' => [Channel::ECHALUPY, false];
        yield 'CS chalupy je jen iCal obsazenost' => [Channel::CS_CHALUPY, false];
    }
}
