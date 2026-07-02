<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Currency;

/**
 * Přepočet částky do CZK uloženým kurzem (ČNB kurz rezervace k DUZP nebo kurz
 * faktury). Sjednocuje opakovaný vzorec „když už je CZK vrať beze změny, jinak
 * přenásob kurzem" napříč cashflow (příjem) a ekonomikou (zisk).
 *
 * Nefetchuje kurz z API — pracuje s už uloženým kurzem. Fresh kurz z ČNB řeší
 * `App\Cnb`/`InvoiceService` při vystavení faktury.
 */
final class CurrencyConverter
{
    /**
     * Vrací částku v CZK, nebo null když ji nelze určit — částka je null, nebo
     * je v cizí měně a kurz chybí. CZK částka se vrací beze změny.
     */
    public function toCzk(?string $amount, string $currency, ?string $rate): ?string
    {
        if ($amount === null) {
            return null;
        }
        if ($currency === 'CZK') {
            return $amount;
        }

        return $rate !== null ? bcmul($amount, $rate, 2) : null;
    }
}
