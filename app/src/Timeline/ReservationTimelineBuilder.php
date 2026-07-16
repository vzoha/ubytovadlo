<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Timeline;

use App\Entity\Reservation;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationActionRepository;
use App\Repository\ReservationNoteRepository;

/**
 * Sestaví časovou osu rezervace: odvozené systémové události (neukládané) +
 * ruční poznámky + naplánované akce, seřazené chronologicky.
 */
class ReservationTimelineBuilder
{
    public function __construct(
        private readonly ReservationNoteRepository $notes,
        private readonly ReservationActionRepository $actions,
        private readonly InvoiceRepository $invoices,
    ) {
    }

    /**
     * @return TimelineItem[] seřazené vzestupně podle data
     */
    public function build(Reservation $reservation): array
    {
        $items = $this->systemEvents($reservation);

        foreach ($this->notes->findForReservation($reservation) as $note) {
            $items[] = TimelineItem::fromNote($note);
        }

        foreach ($this->actions->findForReservation($reservation) as $action) {
            $items[] = TimelineItem::fromAction($action);
        }

        usort($items, static function (TimelineItem $a, TimelineItem $b): int {
            return $a->at <=> $b->at;
        });

        return $items;
    }

    /**
     * @return TimelineItem[]
     */
    private function systemEvents(Reservation $reservation): array
    {
        $items = [];

        $items[] = TimelineItem::event(
            $reservation->getCreatedAt(),
            '➕',
            'Rezervace založena',
            $reservation->getChannel()->label(),
        );

        if ($reservation->getBookedAt() !== null) {
            $items[] = TimelineItem::event($reservation->getBookedAt(), '🗓️', 'Host objednal pobyt');
        }

        foreach ($this->invoices->findForReservation($reservation) as $invoice) {
            $amount = number_format((float) $invoice->getTotalAmount(), 0, ',', ' ') . ' ' . $invoice->getCurrencyLabel();
            $items[] = TimelineItem::event(
                $invoice->getIssuedAt(),
                '🧾',
                sprintf('Vystavena %s %s', mb_strtolower($invoice->getType()->label()), $invoice->getNumber()),
                $amount,
                dateOnly: true,
            );
            if ($invoice->getPaidAt() !== null) {
                $items[] = TimelineItem::event(
                    $invoice->getPaidAt(),
                    '✅',
                    sprintf('Zaplacena faktura %s', $invoice->getNumber()),
                    $amount,
                    dateOnly: true,
                );
            }
        }

        if ($reservation->getCheckinCompletedAt() !== null) {
            $items[] = TimelineItem::event($reservation->getCheckinCompletedAt(), '📋', 'Host dokončil online check-in');
        }

        if ($reservation->getUbyportReport()->getExportedAt() !== null) {
            $items[] = TimelineItem::event($reservation->getUbyportReport()->getExportedAt(), '🛂', 'Nahlášeno na Ubyport');
        }

        if ($reservation->getUbyportReport()->getConfirmedAt() !== null) {
            $items[] = TimelineItem::event($reservation->getUbyportReport()->getConfirmedAt(), '🛂', 'Ubyport potvrzen (doručenka)');
        }

        if ($reservation->getPayoutSentAt() !== null) {
            $items[] = TimelineItem::event($reservation->getPayoutSentAt(), '💸', 'OTA odeslala výplatu');
        }

        return $items;
    }
}
