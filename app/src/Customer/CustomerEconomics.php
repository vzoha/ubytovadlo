<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

use App\Entity\Reservation;
use App\Enum\ReservationStatus;
use App\Profit\ReservationProfit;

/**
 * Ekonomika hosta napříč pobyty — součty z `ReservationProfit` v Kč.
 * Uskutečněné pobyty (dokončené, probíhající a zrušené s přijatými penězi)
 * se drží zvlášť od nadcházejících, jejichž příjem je teprve očekávaný.
 */
final readonly class CustomerEconomics
{
    private const array UPCOMING = [ReservationStatus::CONFIRMED, ReservationStatus::NEEDS_DETAILS];

    public function __construct(
        public string $incomeCzk,
        public string $profitCzk,
        public int $nights,
        public int $stays,
        public string $upcomingIncomeCzk,
        public int $upcomingStays,
        /** Některý uskutečněný pobyt má příjem jen odhadnutý (bez faktury). */
        public bool $hasEstimates,
        /** Některý uskutečněný pobyt nemá cenu — součty jsou neúplné. */
        public bool $missingIncome,
    ) {
    }

    public static function none(): self
    {
        return self::fromStays([]);
    }

    /**
     * @param list<array{Reservation, ReservationProfit}> $stays
     */
    public static function fromStays(array $stays): self
    {
        $income = $profit = $upcomingIncome = '0.00';
        $nights = $realized = $upcoming = 0;
        $hasEstimates = $missingIncome = false;

        foreach ($stays as [$reservation, $stay]) {
            if (\in_array($reservation->getStatus(), self::UPCOMING, true)) {
                $upcomingIncome = bcadd($upcomingIncome, $stay->incomeCzk ?? '0.00', 2);
                $upcoming++;
                continue;
            }

            $income = bcadd($income, $stay->incomeCzk ?? '0.00', 2);
            $profit = bcadd($profit, $stay->profitCzk ?? '0.00', 2);
            if (!$stay->cancelled) {
                $nights += $stay->nights;
                $realized++;
            }
            $hasEstimates = $hasEstimates || $stay->incomeIsEstimate;
            $missingIncome = $missingIncome || $stay->missingIncome;
        }

        return new self($income, $profit, $nights, $realized, $upcomingIncome, $upcoming, $hasEstimates, $missingIncome);
    }

    public function profitPerNightCzk(): ?string
    {
        return $this->nights > 0 ? bcdiv($this->profitCzk, (string) $this->nights, 2) : null;
    }

    public function incomePerStayCzk(): ?string
    {
        return $this->stays > 0 ? bcdiv($this->incomeCzk, (string) $this->stays, 2) : null;
    }
}
