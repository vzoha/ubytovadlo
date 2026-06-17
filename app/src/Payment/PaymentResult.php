<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Reservation;

/**
 * Výsledek zpracování příchozí platby: buď navázaná rezervace (processed),
 * nebo důvod, proč platbu nešlo spárovat (ignored — platba se přesto zaeviduje).
 */
final class PaymentResult
{
    private function __construct(
        public readonly ?Reservation $reservation,
        public readonly ?string $ignoredReason,
    ) {
    }

    public static function matched(Reservation $reservation): self
    {
        return new self($reservation, null);
    }

    public static function unmatched(string $reason): self
    {
        return new self(null, $reason);
    }
}
