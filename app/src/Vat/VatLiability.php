<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Vat;

/**
 * Daňová povinnost měsíce: výstupní DPH z faktur, reverse charge z provizí, odpočet
 * (u plátce se RC vyruší) a výsledná povinnost k úhradě. Vše v CZK.
 */
final readonly class VatLiability
{
    public function __construct(
        public float $outputVatCzk,
        public float $reverseChargeVatCzk,
        public float $deductibleVatCzk,
        public float $totalCzk,
    ) {
    }
}
