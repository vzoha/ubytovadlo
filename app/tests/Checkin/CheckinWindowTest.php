<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Checkin;

use App\Checkin\CheckinWindow;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class CheckinWindowTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function tokenDays(): iterable
    {
        yield 'rok před příjezdem' => ['2025-07-10', true];
        yield 'den odjezdu' => ['2026-07-13', true];
        yield 'poslední den lhůty' => ['2026-07-20', true];
        yield 'den po lhůtě' => ['2026-07-21', false];
    }

    #[DataProvider('tokenDays')]
    public function testTokenClosesAWeekAfterDeparture(string $today, bool $allowed): void
    {
        self::assertSame($allowed, $this->window($today)->allowsToken($this->reservation()));
    }

    public function testTokenFallsBackToCheckInWithoutCheckOut(): void
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-07-10'));

        self::assertTrue($this->window('2026-07-17')->allowsToken($reservation));
        self::assertFalse($this->window('2026-07-18')->allowsToken($reservation));
    }

    public function testCancelledReservationIsClosed(): void
    {
        $reservation = $this->reservation()->setStatus(ReservationStatus::CANCELLED);

        self::assertFalse($this->window('2026-07-11')->allowsToken($reservation));
        self::assertFalse($this->window('2026-07-11')->allowsLookup($reservation));
    }

    public function testLookupOpensHalfAYearBeforeArrival(): void
    {
        self::assertFalse($this->window('2026-01-10')->allowsLookup($this->reservation()));
        self::assertTrue($this->window('2026-01-11')->allowsLookup($this->reservation()));
        self::assertFalse($this->window('2026-07-21')->allowsLookup($this->reservation()));
    }

    private function window(string $today): CheckinWindow
    {
        return new CheckinWindow(new MockClock(new \DateTimeImmutable($today . ' 15:30')));
    }

    private function reservation(): Reservation
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-07-10'));
        $reservation->setCheckOut(new \DateTimeImmutable('2026-07-13'));

        return $reservation;
    }
}
