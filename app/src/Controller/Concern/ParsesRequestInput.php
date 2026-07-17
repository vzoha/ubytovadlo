<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Controller\Concern;

use App\Formatting\Money;

/**
 * Čtení hodnot z POST formuláře pro controllery: datum a peněžní částka.
 * Obojí vrací `null`, když hodnota chybí nebo jí nejde rozumět — jak na to
 * uživatele upozornit (flash, výchozí hodnota, návrat na formulář) rozhoduje
 * volající, protože to se akci od akce liší.
 *
 * Datum se čte volně (`new \DateTimeImmutable`), takže projde `date`
 * i `datetime-local` vstup. Faktury mají vlastní striktní parser — číselná
 * řada je vázaná na rok, tam se hodí jen `Y-m-d`.
 */
trait ParsesRequestInput
{
    protected function parseDateOrNull(?string $raw): ?\DateTimeImmutable
    {
        $trimmed = trim((string) $raw);
        if ($trimmed === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($trimmed);
        } catch (\Exception) {
            return null;
        }
    }

    /** Částka jako decimal string („1 234,50" → „1234.50"), nebo null u nečitelného vstupu. */
    protected function parseAmountOrNull(?string $raw): ?string
    {
        return Money::parse($raw);
    }
}
