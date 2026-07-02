<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

use Granam\CzechVocative\CzechName;

/**
 * Skloňování křestního jména do 5. pádu (vokativ) pro oslovení v e-mailech —
 * „Dobrý den, Petře". Tenký obal nad granam/czech-vocative: ošetří prázdný vstup
 * (knihovna by z '' udělala „E") a bere jen první slovo jména, protože oslovení
 * jede na křestní jméno. U cizích jmen knihovna volí nejbližší českou koncovku.
 */
final class GuestVocative
{
    private readonly CzechName $czechName;

    public function __construct()
    {
        $this->czechName = new CzechName();
    }

    /** Křestní jméno (první slovo) v 5. pádu; prázdný vstup → prázdný řetězec. */
    public function firstName(?string $fullName): string
    {
        $tokens = self::tokens($fullName);

        return $tokens === [] ? '' : $this->czechName->vocative($tokens[0]);
    }

    /**
     * Příjmení (poslední slovo) v 5. pádu; u jednoslovného jména → prázdný řetězec.
     * Skloňuje se v režimu příjmení (např. „Svoboda" → „Svobodo").
     */
    public function lastName(?string $fullName): string
    {
        $tokens = self::tokens($fullName);

        return \count($tokens) < 2 ? '' : $this->czechName->vocative(end($tokens), null, true);
    }

    /** @return list<string> jednotlivá slova jména bez prázdných */
    private static function tokens(?string $fullName): array
    {
        return array_values(array_filter(explode(' ', trim((string) $fullName)), static fn (string $t): bool => $t !== ''));
    }
}
