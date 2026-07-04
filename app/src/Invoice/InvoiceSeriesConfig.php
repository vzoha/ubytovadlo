<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Repository\SettingRepository;

/**
 * Navázání číselné řady faktur — mapa rok → první pořadové číslo v tom roce.
 * Číslování se každý rok resetuje na 1; pokud se navazuje na dosavadní řadu,
 * první číslo roku se posune (např. 2026 → 12, když 2026001–2026011 vznikly dřív).
 *
 * Přednost má hodnota z DB (setting `invoice.series_starts`, JSON), fallback je
 * env INVOICE_SERIES_STARTS — instance si mapu nastaví v UI (/nastaveni/fakturace).
 */
final class InvoiceSeriesConfig
{
    public const KEY = 'invoice.series_starts';

    /** @param array<int, int> $envFallback */
    public function __construct(
        private readonly SettingRepository $settings,
        private readonly array $envFallback = [],
    ) {
    }

    /**
     * Celá mapa rok → první pořadové číslo (DB, s fallbackem na env).
     *
     * @return array<int, int>
     */
    public function all(): array
    {
        $stored = $this->settings->getString(self::KEY);
        if ($stored === null || $stored === '') {
            return $this->envFallback;
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return $this->envFallback;
        }

        $map = [];
        foreach ($decoded as $year => $start) {
            if (is_numeric($year) && is_numeric($start)) {
                $map[(int) $year] = (int) $start;
            }
        }

        return $map;
    }

    /** První pořadové číslo pro daný rok (1, když není navázání). */
    public function startForYear(int $year): int
    {
        return $this->all()[$year] ?? 1;
    }
}
