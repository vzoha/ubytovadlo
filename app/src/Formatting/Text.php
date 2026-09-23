<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Formatting;

/** Drobné úpravy textu ze vstupu (formulář, import, e-mail). */
final class Text
{
    /** Ořízne mezery; prázdný řetězec je null — nevyplněné pole se ukládá jako NULL. */
    public static function nullIfBlank(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
