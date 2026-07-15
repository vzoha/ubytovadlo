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
 * prázdné výsledky se vynechají.
 */
final class GuestMessageTexts
{
    public function __construct(
        private readonly QuickMessageRepository $messages,
        private readonly MessageVariableResolver $variables,
    ) {
    }

    /**
     * @return list<array{label: string, text: string}>
     */
    public function forReservation(Reservation $reservation): array
    {
        $texts = [];
        foreach ($this->messages->findOrdered() as $message) {
            $text = trim($this->variables->render($message->getBody(), $reservation));
            if ($text === '') {
                continue;
            }
            $texts[] = ['label' => $message->getLabel(), 'text' => $text];
        }

        return $texts;
    }
}
