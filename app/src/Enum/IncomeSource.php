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
 * Odkud je odvozen příjem rezervace (ReservationIncome). Vyšší priorita =
 * reálnější zdroj; upsert přepíše uložený příjem jen zdrojem stejné či vyšší
 * priority. Tím se OTA výplata (odhad → reálná výplata) postupně zpřesní a nikdy
 * nezapočte dvakrát.
 *
 * Rozlišení dle kanálu: u přímé objednávky je reálný příjem zaplacená faktura
 * (host platí přímo). U OTA je faktura vystavená v průběhu pobytu jen doklad —
 * reálné peníze dorazí až výplatou (net, později), do té doby je příjem odhad.
 */
enum IncomeSource: string
{
    case OTA_PAYOUT = 'ota_payout';
    case PAID_INVOICE = 'paid_invoice';
    case MANUAL_PAYMENT = 'manual_payment';
    case BANK_PAYMENT = 'bank_payment';
    case ESTIMATE = 'estimate';

    public function label(): string
    {
        return match ($this) {
            self::OTA_PAYOUT => 'OTA výplata',
            self::PAID_INVOICE => 'Zaplacená faktura',
            self::MANUAL_PAYMENT => 'Ruční platba',
            self::BANK_PAYMENT => 'Bankovní platba',
            self::ESTIMATE => 'Odhad',
        };
    }

    /**
     * Vyšší = reálnější. Reálná OTA výplata (net, parsovaná z Airbnb mailu nebo
     * ručně zadaná) přepíše odhad; zaplacená faktura je reálný příjem u přímé
     * objednávky.
     */
    public function priority(): int
    {
        return match ($this) {
            self::OTA_PAYOUT => 40,
            self::MANUAL_PAYMENT => 35,
            self::PAID_INVOICE => 30,
            self::BANK_PAYMENT => 20,
            self::ESTIMATE => 10,
        };
    }

    /** Zdroj reálného přijetí peněz (ne pouhý odhad) — pro realizovaný příjem. */
    public function isRealized(): bool
    {
        return $this !== self::ESTIMATE;
    }
}
