<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use App\Entity\MessageTemplate;
use App\Entity\Reservation;

/**
 * Spočítá okamžik odeslání zprávy z jejího časování (kotva + posun ve dnech +
 * hodina) a konkrétní rezervace. Vrátí null, když šablona nemá časování nebo
 * rezervace danou kotvu postrádá (typicky chybějící odjezd).
 */
class MessageScheduleResolver
{
    public function resolve(MessageTemplate $template, Reservation $reservation): ?\DateTimeImmutable
    {
        $anchor = $template->getAnchor();
        if ($anchor === null) {
            return null;
        }

        $base = $anchor->dateFor($reservation);
        if ($base === null) {
            return null;
        }

        $offset = $template->getOffsetDays() ?? 0;
        $when = $offset !== 0 ? $base->modify(sprintf('%+d days', $offset)) : $base;

        $sendAt = $template->getSendAt();
        if ($sendAt !== null) {
            [$h, $m] = array_map('intval', explode(':', $sendAt));
            $when = $when->setTime($h, $m);
        }

        return $when;
    }
}
