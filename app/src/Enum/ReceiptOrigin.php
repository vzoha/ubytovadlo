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
 * i doplatek); `MANUAL_PAYMENT` nese pořadové číslo ruční platby (host může
 * platit víc splátkami); `PAYOUT`/`ESTIMATE`/`MANUAL` jsou singletony (originId = 0).
 */
enum ReceiptOrigin: string
{
    case INVOICE = 'invoice';
    case PAYMENT = 'payment';
    case PAYOUT = 'payout';
    case ESTIMATE = 'estimate';
    case MANUAL = 'manual';
    case MANUAL_PAYMENT = 'manual_payment';

    /** Ruční záznam chráněný proti auto-přepočtu (OTA výplata i platba hosta). */
    public function isManual(): bool
    {
        return $this === self::MANUAL || $this === self::MANUAL_PAYMENT;
    }
}
