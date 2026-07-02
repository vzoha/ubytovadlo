<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Formatting;

/**
 * Formátování částky v českém formátu (1 234,50) se symbolem měny — CZK jako
 * „Kč", ostatní jako ISO kód. Jedno místo pro celou appku (UI přes MoneyExtension,
 * e-maily přes rendery), ať se částky všude zobrazují stejně.
 */
final class Money
{
    public static function format(float|int|string|null $amount, ?string $currency = 'CZK', int $decimals = 2): string
    {
        $amount ??= 0;
        $formatted = number_format((float) $amount, $decimals, ',', ' ');
        $symbol = $currency === 'CZK' ? 'Kč' : (string) $currency;

        return trim($formatted . ' ' . $symbol);
    }
}
