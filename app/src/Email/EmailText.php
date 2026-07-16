<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email;

/**
 * Čištění textu z e-mailů před parsováním — jedno místo pro celou rodinu parserů
 * (Airbnb, Booking, ČS), ať se text normalizuje všude stejně.
 */
final class EmailText
{
    /**
     * Sjednotí bílé znaky: pevnou i úzkou nedělitelnou mezeru převede na obyčejnou,
     * zkolabuje posloupnosti mezer/tabů na jednu a ořeže okraje.
     */
    public static function normalizeWhitespace(string $text): string
    {
        $text = str_replace(["\xc2\xa0", "\xe2\x80\xaf"], ' ', $text); // nbsp + narrow nbsp
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
