<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Timeline;

use App\Checkin\CheckinProgressSummarizer;
use App\Entity\Reservation;
use App\Enum\ActionStatus;
use App\Mail\GuestMessageDelivery;
use App\Mail\MessageLocales;
use App\Repository\CheckinEventRepository;
use App\Repository\GuestEmailRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationActionRepository;
use App\Repository\ReservationNoteRepository;

/**
 * Sestaví časovou osu rezervace: odvozené systémové události (neukládané) +
 * průběh online check-inu + ruční poznámky + naplánované akce + přiřazené
 * e-maily, seřazené chronologicky.
 */
class ReservationTimelineBuilder
{
    public function __construct(
        private readonly ReservationNoteRepository $notes,
        private readonly ReservationActionRepository $actions,
        private readonly InvoiceRepository $invoices,
        private readonly GuestMessageDelivery $delivery,
        private readonly GuestMessageOutlookResolver $outlook,
        private readonly GuestEmailRepository $emails,
        private readonly CheckinEventRepository $checkinEvents,
        private readonly CheckinProgressSummarizer $checkinProgress,
    ) {
    }

    /**
     * @return TimelineItem[] seřazené vzestupně podle data
     */
    public function build(Reservation $reservation): array
    {
        $items = $this->systemEvents($reservation);

        $progress = $this->checkinProgress($reservation);
        if ($progress !== null) {
            $items[] = $progress;
        }

        foreach ($this->notes->findForReservation($reservation) as $note) {
            $items[] = TimelineItem::fromNote($note);
        }

        foreach ($this->emails->findForReservation($reservation) as $email) {
            $items[] = TimelineItem::fromEmail($email);
        }

        $byChat = $this->delivery->byChat($reservation);
        foreach ($this->actions->findForReservation($reservation) as $action) {
            // Jak zpráva dopadne, má smysl u akce, která teprve čeká — uzavřená
            // už svůj výsledek nese.
            $outlook = $action->getStatus() === ActionStatus::PLANNED ? $this->outlook->forAction($action) : null;
            $items[] = TimelineItem::fromAction($action, $byChat, $outlook);
        }

        usort($items, static function (TimelineItem $a, TimelineItem $b): int {
            return $a->at <=> $b->at;
        });

        return $items;
    }

    /** Kroky hosta v online check-inu jako jedna položka se souhrnem a sbalenými kroky. */
    private function checkinProgress(Reservation $reservation): ?TimelineItem
    {
        $progress = $this->checkinProgress->summarize(
            $this->checkinEvents->findForReservation($reservation),
            $reservation->getCheckinCompletedAt(),
        );
        if ($progress === null) {
            return null;
        }

        return TimelineItem::event($progress->startedAt, '🧭', 'Průběh online check-inu', $progress->summary, body: implode("\n", $progress->steps));
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

        if ($reservation->getGuestLocaleChosenAt() !== null) {
            $items[] = TimelineItem::event(
                $reservation->getGuestLocaleChosenAt(),
                '🌐',
                'Host si zvolil jazyk komunikace',
                MessageLocales::label(MessageLocales::normalize($reservation->getGuestLocale())),
            );
        }

        if ($reservation->getUbyportReport()->getExportedAt() !== null) {
            $items[] = TimelineItem::event($reservation->getUbyportReport()->getExportedAt(), '🛂', 'Nahlášeno na Ubyport');
        }

        if ($reservation->getUbyportReport()->getConfirmedAt() !== null) {
            $items[] = TimelineItem::event($reservation->getUbyportReport()->getConfirmedAt(), '🛂', 'Ubyport potvrzen (doručenka)');
        }

        if ($reservation->getPayoutSentAt() !== null) {
            // Výplata nese jen datum — čas 00:00 by na ose tvrdil, co nevíme.
            $items[] = TimelineItem::event($reservation->getPayoutSentAt(), '💸', 'OTA odeslala výplatu', dateOnly: true);
        }

        return $items;
    }
}
