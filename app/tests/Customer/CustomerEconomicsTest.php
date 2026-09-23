<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Customer\CustomerEconomics;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ReservationStatus;
use App\Profit\ReservationProfit;
use PHPUnit\Framework\TestCase;

final class CustomerEconomicsTest extends TestCase
{
    private function reservation(ReservationStatus $status): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-06-01'));
        $r->setStatus($status);

        return $r;
    }

    private function profit(?string $income, ?string $profit, int $nights, bool $cancelled = false, bool $estimate = false): ReservationProfit
    {
        return new ReservationProfit(
            nights: $nights,
            incomeCzk: $income,
            incomeIsEstimate: $estimate,
            commissionCzk: '0.00',
            vatCzk: '0.00',
            vatDeductible: false,
            electricityCzk: '0.00',
            cleaningCzk: '0.00',
            recreationFeeCzk: '0.00',
            expensesTotalCzk: '0.00',
            profitCzk: $profit,
            profitPerNightCzk: null,
            cancelled: $cancelled,
            missingIncome: $income === null,
            missingElectricity: false,
            missingCleaning: false,
        );
    }

    public function testSumsRealizedStaysAndKeepsUpcomingApart(): void
    {
        $economics = CustomerEconomics::fromStays([
            [$this->reservation(ReservationStatus::COMPLETED), $this->profit('6000.00', '4500.00', 3)],
            [$this->reservation(ReservationStatus::IN_PROGRESS), $this->profit('4000.00', '3000.00', 2, estimate: true)],
            [$this->reservation(ReservationStatus::CONFIRMED), $this->profit('5000.00', '4000.00', 2)],
        ]);

        self::assertSame('10000.00', $economics->incomeCzk);
        self::assertSame('7500.00', $economics->profitCzk);
        self::assertSame(5, $economics->nights);
        self::assertSame(2, $economics->stays);
        self::assertSame('1500.00', $economics->profitPerNightCzk());
        self::assertSame('5000.00', $economics->incomePerStayCzk());
        self::assertSame('5000.00', $economics->upcomingIncomeCzk);
        self::assertSame(1, $economics->upcomingStays);
        self::assertTrue($economics->hasEstimates);
        self::assertFalse($economics->missingIncome);
    }

    public function testCancelledStayAddsOnlyRealMoneyWithoutNights(): void
    {
        $economics = CustomerEconomics::fromStays([
            [$this->reservation(ReservationStatus::CANCELLED), $this->profit('1000.00', '1000.00', 3, cancelled: true)],
        ]);

        self::assertSame('1000.00', $economics->incomeCzk);
        self::assertSame(0, $economics->nights);
        self::assertSame(0, $economics->stays);
        self::assertNull($economics->profitPerNightCzk());
        self::assertNull($economics->incomePerStayCzk());
    }

    public function testMissingPriceIsFlaggedAndCountsAsZero(): void
    {
        $economics = CustomerEconomics::fromStays([
            [$this->reservation(ReservationStatus::COMPLETED), $this->profit(null, null, 2)],
        ]);

        self::assertSame('0.00', $economics->incomeCzk);
        self::assertSame(1, $economics->stays);
        self::assertTrue($economics->missingIncome);
    }

    public function testNoStaysIsEmpty(): void
    {
        $economics = CustomerEconomics::fromStays([]);

        self::assertSame('0.00', $economics->incomeCzk);
        self::assertSame(0, $economics->upcomingStays);
        self::assertNull($economics->profitPerNightCzk());
    }
}
