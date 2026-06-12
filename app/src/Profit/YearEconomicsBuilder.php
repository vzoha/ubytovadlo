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
 * Sestaví roční ekonomiku pro přehled i dashboard. Pobyty dělí na
 * uskutečněné (odjezd proběhl) a očekávané (budoucí/probíhající) —
 * u neproběhlých chybí elektřina a reálný úklid, takže jejich zisk
 * je jen výhled a nesmí se míchat do reálných čísel.
 *
 * @phpstan-type Summary array{count: int, nights: int, income: string, expenses: string, profit: string, hasEstimates: bool}
 */
final class YearEconomicsBuilder
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
     *     realized: Summary,
     *     expected: Summary,
     *     total: Summary,
     *     byChannel: array<string, Summary>,
     * }
     */
    public function build(int $year, \DateTimeImmutable $today): array
    {
        $reservations = $this->reservations->findForEconomicsYear($year);
        $profits = $this->profitCalculator->calculateBatch($reservations);

        $realized = self::emptySummary();
        $expected = self::emptySummary();
        $total = self::emptySummary();
        $byChannel = [];

        foreach ($reservations as $reservation) {
            $profit = $profits[$reservation->getId()];
            $channel = $reservation->getChannel()->value;
            $byChannel[$channel] ??= self::emptySummary();

            self::add($total, $profit);
            self::add($byChannel[$channel], $profit);
            if (self::isRealized($reservation, $today)) {
                self::add($realized, $profit);
            } else {
                self::add($expected, $profit);
            }
        }

        return [
            'reservations' => $reservations,
            'profits' => $profits,
            'realized' => $realized,
            'expected' => $expected,
            'total' => $total,
            'byChannel' => $byChannel,
        ];
    }

    /** Pobyt je uskutečněný, jakmile proběhl odjezd. Probíhající pobyt patří do očekávaných. */
    public static function isRealized(Reservation $reservation, \DateTimeImmutable $today): bool
    {
        return ($reservation->getCheckOut() ?? $reservation->getCheckIn()) <= $today;
    }

    /**
     * @phpstan-return Summary
     */
    private static function emptySummary(): array
    {
        return ['count' => 0, 'nights' => 0, 'income' => '0.00', 'expenses' => '0.00', 'profit' => '0.00', 'hasEstimates' => false];
    }

    /**
     * @phpstan-param Summary $summary
     */
    private static function add(array &$summary, ReservationProfit $profit): void
    {
        $summary['count']++;
        $summary['nights'] += $profit->nights;
        if ($profit->incomeCzk !== null) {
            $summary['income'] = bcadd($summary['income'], $profit->incomeCzk, 2);
        }
        $summary['expenses'] = bcadd($summary['expenses'], $profit->expensesTotalCzk, 2);
        if ($profit->profitCzk !== null) {
            $summary['profit'] = bcadd($summary['profit'], $profit->profitCzk, 2);
        }
        $summary['hasEstimates'] = $summary['hasEstimates'] || $profit->incomeIsEstimate;
    }
}
