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
use App\Enum\Channel;
use App\Enum\IncomeSource;
use App\Enum\PaymentStatus;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationReceiptRepository;

/**
 * Stav platby (Nezaplaceno/Částečně/Zaplaceno) po rezervacích — dávkově pro
 * seznam, bez N+1. Dává smysl jen u web/přímé rezervace v Kč (u OTA platí host
 * platformě). Zaplaceno = zaplacené faktury + ruční platby.
 */
final class PaymentStatusResolver
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly ReservationReceiptRepository $receipts,
    ) {
    }

    /**
     * @param Reservation[] $reservations
     *
     * @return array<int, PaymentStatus> jen rezervace, kde stav platby dává smysl
     */
    public function batch(array $reservations): array
    {
        $applicable = array_filter($reservations, [$this, 'applies']);
        $ids = array_map(static fn (Reservation $r): int => (int) $r->getId(), $applicable);

        $paidInvoices = $this->invoices->sumPaidCzkByReservations($ids);
        $manualPayments = $this->receipts->sumBySourceForReservations(IncomeSource::MANUAL_PAYMENT, $ids);

        $out = [];
        foreach ($applicable as $reservation) {
            $id = (int) $reservation->getId();
            $paid = ($paidInvoices[$id] ?? 0.0) + ($manualPayments[$id] ?? 0.0);
            $out[$id] = PaymentStatus::fromAmounts((float) $reservation->getPriceTotal(), $paid);
        }

        return $out;
    }

    private function applies(Reservation $reservation): bool
    {
        return $reservation->getPriceTotal() !== null
            && $reservation->getPriceCurrency() === 'CZK'
            && $reservation->getBillingMode() !== BillingMode::WAIVED
            && \in_array($reservation->getChannel(), [Channel::WEB, Channel::DIRECT], true);
    }
}
