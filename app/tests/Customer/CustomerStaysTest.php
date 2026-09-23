<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Customer\CustomerStays;
use App\Entity\Reservation;
use App\Enum\Channel;
use PHPUnit\Framework\TestCase;

final class CustomerStaysTest extends TestCase
{
    private function stay(string $checkIn): Reservation
    {
        return new Reservation(Channel::WEB, new \DateTimeImmutable($checkIn));
    }

    public function testOrdinalAndOthersNewestFirst(): void
    {
        $first = $this->stay('2025-05-01');
        $second = $this->stay('2025-08-01');
        $third = $this->stay('2026-05-01');

        $stays = new CustomerStays($second, [$first, $second, $third]);

        self::assertSame(2, $stays->ordinal());
        self::assertSame(3, $stays->count());
        self::assertTrue($stays->isReturning());
        self::assertSame([$third, $first], $stays->others());
    }

    public function testSingleStayIsNotReturning(): void
    {
        $only = $this->stay('2026-05-01');

        $stays = new CustomerStays($only, [$only]);

        self::assertSame(1, $stays->ordinal());
        self::assertFalse($stays->isReturning());
        self::assertSame([], $stays->others());
    }

    public function testCancelledReservationHasNoOrdinalButShowsOtherStays(): void
    {
        $cancelled = $this->stay('2026-05-01');
        $earlier = $this->stay('2025-05-01');

        $stays = new CustomerStays($cancelled, [$earlier]);

        self::assertNull($stays->ordinal());
        self::assertTrue($stays->isReturning());
        self::assertSame([$earlier], $stays->others());
    }
}
