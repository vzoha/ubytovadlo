<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

/**
 * Jeden řádek rekapitulace DPH: základ, daň a částka s daní pro jednu sazbu.
 * Všechny hodnoty jsou řetězce s pevnou desetinnou přesností (koruny).
 */
final readonly class InvoiceVatRecapRow
{
    public function __construct(
        public string $rate,
        public string $base,
        public string $vat,
        public string $gross,
    ) {
    }
}
