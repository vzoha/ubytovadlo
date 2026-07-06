<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Entity\Reservation;
use App\Enum\GuestMessageStatus;
use App\Enum\MessageKind;
use App\Enum\OwnerNotificationType;
use App\Enum\ReservationStatus;
use App\Notification\OwnerNotifier;
use App\Repository\GuestMessageRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Potvrdí rezervaci: přepne stav na „Potvrzeno" a pošle hostovi potvrzovací
 * e-mail. Volá se automaticky po přijetí zálohy i ručně tlačítkem z detailu.
 *
 * Automatické potvrzení ctí vypnutou šablonu a neposílá zprávu podruhé; ruční
 * potvrzení je výslovný krok provozovatele, takže pošle vždy (i opakovaně).
 */
class ReservationConfirmation
{
    public function __construct(
        private readonly GuestMessageSender $sender,
        private readonly MessageTemplateProvider $templates,
        private readonly GuestMessageRepository $messages,
        private readonly EntityManagerInterface $em,
        private readonly OwnerNotifier $notifier,
    ) {
    }

    public function confirm(Reservation $reservation, bool $explicit): ConfirmationResult
    {
        if (\in_array($reservation->getStatus(), [ReservationStatus::CANCELLED, ReservationStatus::COMPLETED], true)) {
            return new ConfirmationResult(false, false, 'Rezervace je uzavřená.');
        }

        $statusChanged = false;
        if ($reservation->getStatus() === ReservationStatus::NEEDS_DETAILS) {
            $reservation->setStatus(ReservationStatus::CONFIRMED);
            $statusChanged = true;
        }

        $reason = $this->skipReason($reservation, $explicit);
        if ($reason !== null) {
            if ($statusChanged) {
                $this->em->flush();
            }

            return new ConfirmationResult(false, $statusChanged, $reason);
        }

        $message = $this->sender->send($reservation, MessageKind::RESERVATION_CONFIRMED);
        if ($message->getStatus() === GuestMessageStatus::SENT) {
            return new ConfirmationResult(true, $statusChanged, null);
        }

        // Odeslání selhalo (rezervace už je potvrzená) — upozorni ubytovatele, ať
        // potvrzení pošle ručně; automaticky se to samo nezopakuje.
        $this->notifier->notify(OwnerNotificationType::GUEST_MESSAGE_FAILED, $reservation, [
            'kind' => MessageKind::RESERVATION_CONFIRMED->label(),
            'error' => (string) $message->getError(),
        ]);
        $this->em->flush();

        return new ConfirmationResult(false, $statusChanged, 'Odeslání selhalo: ' . (string) $message->getError());
    }

    /** Důvod, proč potvrzovací e-mail neodeslat, nebo null když se má poslat. */
    private function skipReason(Reservation $reservation, bool $explicit): ?string
    {
        if (!$this->sender->canSend($reservation)) {
            return 'Host nemá e-mail — potvrzení neodesláno.';
        }
        if ($explicit) {
            return null;
        }
        if (!$this->templates->for(MessageKind::RESERVATION_CONFIRMED)->isEnabled()) {
            return 'Šablona potvrzení je vypnutá — neodesláno.';
        }
        if ($this->messages->hasSent($reservation, MessageKind::RESERVATION_CONFIRMED)) {
            return 'Potvrzení už bylo odesláno.';
        }

        return null;
    }
}
