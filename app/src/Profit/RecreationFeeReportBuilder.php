<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Profit;

use App\Entity\Reservation;
use App\Repository\ReservationRepository;

/**
 * Podklad rekreačního poplatku pro obec — za rok sečte počet pobytů,
 * poplatníků (dospělých, děti jsou osvobozené), nocí a částku k odvodu.
 * Uskutečněné pobyty (odjezd proběhl) drží zvlášť od celku, protože obci
 * se hlásí a odvádí jen za skutečně proběhlé pobyty.
 *
 * @phpstan-type Totals array{count: int, adults: int, nights: int, fee: string}
 */
final class RecreationFeeReportBuilder
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationProfitCalculator $profitCalculator,
    ) {
    }

    /**
     * @return array{
     *     reservations: Reservation[],
     *     profits: array<int, ReservationProfit>,
     *     realized: Totals,
     *     total: Totals,
     * }
     */
    public function build(int $year, \DateTimeImmutable $today): array
    {
        $reservations = $this->reservations->findForEconomicsYear($year);
        $profits = $this->profitCalculator->calculateBatch($reservations);

        $realized = self::emptyTotals();
        $total = self::emptyTotals();

        foreach ($reservations as $reservation) {
            $profit = $profits[$reservation->getId()];
            self::add($total, $reservation, $profit);
            if (YearEconomicsBuilder::isRealized($reservation, $today)) {
                self::add($realized, $reservation, $profit);
            }
        }

        return [
            'reservations' => $reservations,
            'profits' => $profits,
            'realized' => $realized,
            'total' => $total,
        ];
    }

    /**
     * @phpstan-return Totals
     */
    private static function emptyTotals(): array
    {
        return ['count' => 0, 'adults' => 0, 'nights' => 0, 'fee' => '0.00'];
    }

    /**
     * @phpstan-param Totals $totals
     */
    private static function add(array &$totals, Reservation $reservation, ReservationProfit $profit): void
    {
        $totals['count']++;
        $totals['adults'] += $reservation->getGuestsAdult();
        $totals['nights'] += $profit->nights;
        $totals['fee'] = bcadd($totals['fee'], $profit->recreationFeeCzk, 2);
    }
}
