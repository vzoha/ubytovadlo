<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\IncomeSource;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationReceiptRepository;

/**
 * Kolik hostovi zbývá doplatit = cena − (zaplacené faktury + ruční platby).
 * Bez DB sloupce, čistě dopočet. Smysl dává jen u CZK ceny a fakturovaných
 * režimů (Booking v EUR vs. faktury v CZK by se nesčítaly — tam vrací null).
 */
class BalanceCalculator
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ReservationReceiptRepository $receipts,
    ) {
    }

    public function forReservation(Reservation $reservation): ?BalanceResult
    {
        $total = $reservation->getPriceTotal();
        if ($total === null) {
            return null;
        }
        if ($reservation->getPriceCurrency() !== 'CZK') {
            return null;
        }
        if ($reservation->getBillingMode() === BillingMode::WAIVED) {
            return null;
        }

        $paid = 0.0;
        foreach ($this->invoices->findForReservation($reservation) as $invoice) {
            if ($invoice->isPaid() && $invoice->getCurrency() === 'CZK') {
                $paid += (float) $invoice->getTotalAmount();
            }
        }
        // Ruční platby (hotovost, převod, záloha bez faktury) — reálné peníze mimo fakturu.
        foreach ($this->receipts->findForReservation($reservation) as $receipt) {
            if ($receipt->getSource() === IncomeSource::MANUAL_PAYMENT) {
                $paid += (float) $receipt->getAmountCzk();
            }
        }

        $totalF = (float) $total;
        $remaining = round($totalF - $paid, 2);

        return new BalanceResult($totalF, round($paid, 2), $remaining);
    }
}
