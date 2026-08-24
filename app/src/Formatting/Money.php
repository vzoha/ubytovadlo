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
 * Jedno místo pro práci s peněžní částkou napříč appkou:
 *
 *  - `normalize()` — kanonický decimal string ("1234.50") pro uložení do DB a
 *    výpočty (Doctrine decimal sloupce drží částky jako string).
 *  - `parse()` — převod uživatelského vstupu ("1 234,50") na decimal string.
 *  - `symbol()` — symbol měny pro zobrazení („Kč", „€"), jinak ISO kód.
 *  - `format()` — zobrazení v českém formátu (1 234,50 Kč) pro UI a e-maily.
 *
 * Přepočet mezi měnami uloženým kurzem řeší `App\Currency\CurrencyConverter`.
 */
final class Money
{
    /** Symboly měn, se kterými se v ubytování potkáváme. */
    private const SYMBOLS = [
        'CZK' => 'Kč',
        'EUR' => '€',
        'USD' => '$',
        'GBP' => '£',
        'PLN' => 'zł',
    ];

    /**
     * Kanonický decimal string ("1234.50") pro uložení do DB a výpočty.
     * `null` se chová jako nula.
     */
    public static function normalize(float|int|string|null $amount, int $scale = 2): string
    {
        return number_format((float) ($amount ?? 0), $scale, '.', '');
    }

    /**
     * Převede uživatelský vstup ("1 234,50", "1234.5") na decimal string.
     * Vrací `null`, když vstup není číslo (prázdný, písmena, …).
     */
    public static function parse(?string $input, int $scale = 2): ?string
    {
        if ($input === null) {
            return null;
        }
        $normalized = str_replace([' ', "\u{00a0}", ','], ['', '', '.'], trim($input));
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        return self::normalize($normalized, $scale);
    }

    /**
     * Symbol měny pro zobrazení. Měna bez známého symbolu se vypíše ISO kódem,
     * `null` → prázdný řetězec.
     */
    public static function symbol(?string $currency): string
    {
        return self::SYMBOLS[$currency] ?? (string) ($currency ?? '');
    }

    /**
     * Zobrazení částky v českém formátu (1 234,50) se symbolem měny pro UI a e-maily.
     */
    public static function format(float|int|string|null $amount, ?string $currency = 'CZK', int $decimals = 2): string
    {
        $formatted = number_format((float) ($amount ?? 0), $decimals, ',', ' ');

        return trim($formatted . ' ' . self::symbol($currency));
    }
}
