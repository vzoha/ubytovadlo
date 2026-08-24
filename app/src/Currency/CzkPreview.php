<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Currency;

use App\Enum\CzkRateSource;

/**
 * Částka v cizí měně přepočtená do CZK i s tím, čím se přepočítala — aby UI
 * mohlo rozlišit zúčtovanou hodnotu od orientačního přepočtu dnešním kurzem.
 */
final class CzkPreview
{
    public function __construct(
        public readonly string $amountCzk,
        public readonly string $rate,
        public readonly ?\DateTimeImmutable $rateDate,
        public readonly CzkRateSource $source,
    ) {
    }

    public function isEstimate(): bool
    {
        return $this->source->isEstimate();
    }
}
