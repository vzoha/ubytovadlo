<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\GuestEmail;

/** Podoba e-mailu z korespondence s hostem pro AI asistenta. */
final class GuestEmailPresenter
{
    /** @return array<string, mixed> */
    public function headline(GuestEmail $email): array
    {
        $reservation = $email->getReservation();

        return [
            'id' => $email->getId(),
            'direction' => $email->isInbound() ? 'od hosta' : 'hostovi',
            'guest' => ['name' => $email->getCounterpartName(), 'email' => $email->getCounterpartEmail()],
            'knownGuest' => $email->getCustomer()?->getDisplayName(),
            'reservation' => $reservation !== null
                ? ['id' => $reservation->getId(), 'checkIn' => $reservation->getCheckIn()->format('Y-m-d')]
                : null,
            'subject' => $email->getSubject(),
            'date' => $email->getSentAt()->format('Y-m-d H:i'),
            'answered' => $email->isInbound() ? $email->isAnswered() : null,
        ];
    }

    /** @return array<string, mixed> */
    public function full(GuestEmail $email): array
    {
        return $this->headline($email) + [
            'messageId' => $email->getMessageId(),
            'text' => $email->getBody(),
            'attachments' => $email->getAttachmentNames(),
        ];
    }
}
