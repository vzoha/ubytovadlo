<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Původ dílčí přijaté platby (ReservationReceipt) — slouží jako idempotentní
 * klíč upsertu: dvojice (originType, originId) jednoznačně identifikuje událost,
 * ze které receipt vznikl, takže recompute jej přepíše místo duplikace.
 *
 * `INVOICE`/`PAYMENT` nesou id zdroje (může jich být víc na rezervaci — záloha
 * i doplatek); `PAYOUT`/`ESTIMATE`/`MANUAL` jsou singletony (originId = 0).
 */
enum ReceiptOrigin: string
{
    case INVOICE = 'invoice';
    case PAYMENT = 'payment';
    case PAYOUT = 'payout';
    case ESTIMATE = 'estimate';
    case MANUAL = 'manual';

    /** Ruční záznam chráněný proti auto-přepočtu (ruční OTA výplata). */
    public function isManual(): bool
    {
        return $this === self::MANUAL;
    }
}
