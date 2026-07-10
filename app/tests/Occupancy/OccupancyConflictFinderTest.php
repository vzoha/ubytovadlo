<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Occupancy;

use App\Entity\Reservation;
use App\Enum\Channel;
use App\Occupancy\OccupancyConflictFinder;
use PHPUnit\Framework\TestCase;

final class OccupancyConflictFinderTest extends TestCase
{
    private OccupancyConflictFinder $finder;

    protected function setUp(): void
    {
        $this->finder = new OccupancyConflictFinder();
    }

    public function testOverlappingReservationsConflict(): void
    {
        $a = $this->reservation('2026-06-01', '2026-06-05', Channel::WEB);
        $b = $this->reservation('2026-06-03', '2026-06-07', Channel::BOOKING);

        $conflicts = $this->finder->find([$a, $b]);

        self::assertCount(1, $conflicts);
        // Průnik 3.–5. 6. = 2 noci.
        self::assertSame('2026-06-03', $conflicts[0]->from->format('Y-m-d'));
        self::assertSame('2026-06-05', $conflicts[0]->to->format('Y-m-d'));
        self::assertSame(2, $conflicts[0]->nights());
    }

    public function testAdjacentStaysDoNotConflict(): void
    {
        // Odjezd 5. 6. = příjezd 5. 6. → stejný den výměny, žádná kolize.
        $a = $this->reservation('2026-06-01', '2026-06-05', Channel::WEB);
        $b = $this->reservation('2026-06-05', '2026-06-08', Channel::AIRBNB);

        self::assertSame([], $this->finder->find([$a, $b]));
    }

    public function testNestedStayConflicts(): void
    {
        $a = $this->reservation('2026-06-01', '2026-06-10', Channel::WEB);
        $b = $this->reservation('2026-06-03', '2026-06-05', Channel::BOOKING);

        self::assertCount(1, $this->finder->find([$a, $b]));
    }

    public function testReservationWithoutCheckoutCountsAsOneNight(): void
    {
        $a = $this->reservation('2026-06-01', null, Channel::WEB);
        $b = $this->reservation('2026-06-01', '2026-06-03', Channel::BOOKING);

        $conflicts = $this->finder->find([$a, $b]);
        self::assertCount(1, $conflicts);
        self::assertSame(1, $conflicts[0]->nights());
    }

    public function testSeparateStaysDoNotConflict(): void
    {
        $a = $this->reservation('2026-06-01', '2026-06-05', Channel::WEB);
        $b = $this->reservation('2026-06-10', '2026-06-14', Channel::BOOKING);

        self::assertSame([], $this->finder->find([$a, $b]));
    }

    public function testThreeWayOverlapReportsEachPair(): void
    {
        $a = $this->reservation('2026-06-01', '2026-06-06', Channel::WEB);
        $b = $this->reservation('2026-06-02', '2026-06-07', Channel::BOOKING);
        $c = $this->reservation('2026-06-03', '2026-06-08', Channel::AIRBNB);

        // páry A×B, A×C, B×C
        self::assertCount(3, $this->finder->find([$a, $b, $c]));
    }

    private function reservation(string $checkIn, ?string $checkOut, Channel $channel): Reservation
    {
        $r = new Reservation($channel, new \DateTimeImmutable($checkIn));
        if ($checkOut !== null) {
            $r->setCheckOut(new \DateTimeImmutable($checkOut));
        }
        $r->setGuestName('Host ' . $checkIn);

        return $r;
    }
}
