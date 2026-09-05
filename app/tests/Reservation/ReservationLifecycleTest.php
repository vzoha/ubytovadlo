<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Reservation;

use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Reservation\ReservationLifecycle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ReservationLifecycleTest extends TestCase
{
    /**
     * @return array<string, array{string, ReservationStatus}>
     */
    public static function calendarProvider(): array
    {
        return [
            'před příjezdem' => ['2026-05-01', ReservationStatus::CONFIRMED],
            'den příjezdu' => ['2026-05-10', ReservationStatus::IN_PROGRESS],
            'uprostřed pobytu' => ['2026-05-11', ReservationStatus::IN_PROGRESS],
            'den odjezdu' => ['2026-05-12', ReservationStatus::IN_PROGRESS],
            'den po odjezdu' => ['2026-05-13', ReservationStatus::COMPLETED],
        ];
    }

    #[DataProvider('calendarProvider')]
    public function testStatusFollowsCalendar(string $today, ReservationStatus $expected): void
    {
        $lifecycle = new ReservationLifecycle(new MockClock($today . ' 14:30'));

        self::assertSame($expected, $lifecycle->derive($this->stay()));
    }

    public function testStayWithoutCheckOutEndsOnArrivalDay(): void
    {
        $reservation = new Reservation(Channel::DIRECT, new \DateTimeImmutable('2026-05-10'));
        $reservation->setStatus(ReservationStatus::CONFIRMED);

        self::assertSame(
            ReservationStatus::IN_PROGRESS,
            (new ReservationLifecycle(new MockClock('2026-05-10 09:00')))->derive($reservation),
        );
        self::assertSame(
            ReservationStatus::COMPLETED,
            (new ReservationLifecycle(new MockClock('2026-05-11 09:00')))->derive($reservation),
        );
    }

    public function testCancelledAndUnfinishedAreLeftAlone(): void
    {
        $lifecycle = new ReservationLifecycle(new MockClock('2026-05-20 10:00'));

        $cancelled = $this->stay();
        $cancelled->setStatus(ReservationStatus::CANCELLED);
        self::assertSame(ReservationStatus::CANCELLED, $lifecycle->derive($cancelled));

        $needsDetails = $this->stay();
        $needsDetails->setStatus(ReservationStatus::NEEDS_DETAILS);
        self::assertSame(ReservationStatus::NEEDS_DETAILS, $lifecycle->derive($needsDetails));
    }

    public function testRestoreWithoutGuestNameGoesBackToNeedsDetails(): void
    {
        $lifecycle = new ReservationLifecycle(new MockClock('2026-05-01 10:00'));

        $reservation = $this->stay();
        $reservation->setStatus(ReservationStatus::CANCELLED);

        self::assertSame(ReservationStatus::NEEDS_DETAILS, $lifecycle->afterRestore($reservation));

        $reservation->setGuestName('Testovací Host');
        self::assertSame(ReservationStatus::CONFIRMED, $lifecycle->afterRestore($reservation));
    }

    private function stay(): Reservation
    {
        $reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-05-10'));
        $reservation->setCheckOut(new \DateTimeImmutable('2026-05-12'));
        $reservation->setStatus(ReservationStatus::CONFIRMED);

        return $reservation;
    }
}
