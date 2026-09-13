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
use App\Repository\QuickMessageRepository;

/**
 * Rychlé zprávy vyrenderované pro konkrétní rezervaci — pro předvyplnění
 * SMS/WhatsApp z detailu rezervace. Placeholdery se dosadí z rezervace,
 * prázdné výsledky se vynechají a na konec každé zprávy se připojí podpis
 * z nastavení.
 */
final class GuestMessageTexts
{
    public function __construct(
        private readonly QuickMessageRepository $messages,
        private readonly MessageVariableResolver $variables,
        private readonly QuickMessageSignature $signature,
    ) {
    }

    /**
     * @return list<array{label: string, text: string}>
     */
    public function forReservation(Reservation $reservation): array
    {
        $signature = trim($this->variables->renderBody($this->signature->current(), $reservation));

        $texts = [];
        foreach ($this->messages->findOrdered() as $message) {
            $text = trim($this->variables->renderBody($message->getBody(), $reservation));
            if ($text === '') {
                continue;
            }
            if ($signature !== '') {
                $text .= "\n\n" . $signature;
            }
            $texts[] = ['label' => $message->getLabel(), 'text' => $text];
        }

        return $texts;
    }
}
