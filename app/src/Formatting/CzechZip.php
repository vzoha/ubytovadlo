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
 * České PSČ se píše s mezerou po třetí číslici („370 01"). Zdroje ho posílají
 * různě — MotoPress jako text od hosta, ARES jako číslo — proto se sjednocuje
 * až tady, ne v {@see \App\Entity\Embeddable\Address}: to nese i zahraniční
 * adresy, kde stejně dlouhé PSČ mezeru nemá (např. německé „10115").
 */
final class CzechZip
{
    /** Pět číslic přepíše na „370 01"; cokoli jiného (zahraniční, neúplné) nechá být. */
    public static function format(int|string|null $zip): ?string
    {
        $trimmed = trim((string) $zip);
        if ($trimmed === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $trimmed) ?? '';

        return \strlen($digits) === 5 ? substr($digits, 0, 3) . ' ' . substr($digits, 3) : $trimmed;
    }
}
