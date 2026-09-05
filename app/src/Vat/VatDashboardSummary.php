<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Vat;

use App\Entity\VatPeriod;
use App\Repository\AirbnbStatementRepository;
use App\Repository\BookingMonthlyInvoiceRepository;
use App\Repository\VatPeriodRepository;

/**
 * Podklad pro kartu DPH na přehledu: aktuální měsíc a nepodané měsíce zpět,
 * včetně chybějících podkladů od portálů. Sestavuje ho zvlášť, aby přehled
 * jen zobrazoval hotová čísla.
 */
final class VatDashboardSummary
{
    private const VAT_LOOKBACK_MONTHS = 6;

    public function __construct(
        private readonly VatPeriodRepository $vatPeriods,
        private readonly VatMonthCalculator $vatCalculator,
        private readonly BookingMonthlyInvoiceRepository $bookingInvoices,
        private readonly AirbnbStatementRepository $airbnbStatements,
    ) {
    }

    /**
     * @return array{
     *   current: array{year:int, month:int, base:float, vat:float, dueAt:\DateTimeImmutable, daysToDue:int},
     *   pending: list<array{
     *     year:int, month:int, base:float, vat:float,
     *     dueAt:\DateTimeImmutable, overdue:bool, daysToDue:int,
     *     missingBookingPdf:bool, missingAirbnbStatement:bool
     *   }>
     * }
     */
    public function build(\DateTimeImmutable $today): array
    {
        $current = $this->vatMonthSummary((int) $today->format('Y'), (int) $today->format('n'), $today);
        $filedKeys = $this->vatPeriods->findFiledKeySet();
        $pending = [];

        $cursor = $today->modify('first day of previous month');
        for ($i = 0; $i < self::VAT_LOOKBACK_MONTHS; ++$i, $cursor = $cursor->modify('first day of previous month')) {
            if (isset($filedKeys[$cursor->format('Y-m')])) {
                continue;
            }

            $row = $this->vatMonthSummary((int) $cursor->format('Y'), (int) $cursor->format('n'), $today);
            // Měsíce, ve kterých nic neproběhlo (0 Kč) a nemají žádné podklady, neukazujeme.
            if ($row['base'] > 0.0 || $row['missingBookingPdf'] || $row['missingAirbnbStatement']) {
                $pending[] = $row;
            }
        }

        return ['current' => $current, 'pending' => $pending];
    }

    /**
     * @return array{
     *   year:int, month:int, base:float, vat:float,
     *   dueAt:\DateTimeImmutable, overdue:bool, daysToDue:int,
     *   missingBookingPdf:bool, missingAirbnbStatement:bool
     * }
     */
    private function vatMonthSummary(int $year, int $month, \DateTimeImmutable $today): array
    {
        $summary = $this->vatCalculator->summarize($year, $month);
        $dueAt = (new VatPeriod($year, $month))->getFilingDueAt();

        return [
            'year' => $year,
            'month' => $month,
            'base' => $summary->sumBaseCzk,
            'vat' => $summary->sumVatCzk,
            'dueAt' => $dueAt,
            'overdue' => $today > $dueAt,
            'daysToDue' => self::daysBetween($today, $dueAt),
            'missingBookingPdf' => $summary->hasBookingReservations
                && $this->bookingInvoices->findByPeriodMonth($year, $month) === null,
            'missingAirbnbStatement' => $summary->hasAirbnbReservations
                && $this->airbnbStatements->findAllByPeriodMonth($year, $month) === [],
        ];
    }

    /** Počet dní mezi dvěma daty, znaménkový (záporné = $to v minulosti vůči $from). */
    private static function daysBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $from->diff($to)->format('%r%a');
    }
}
