<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Vat;

final class CnbRate
{
    public function __construct(
        public readonly string $currencyCode,
        public readonly float $rate,
        public readonly \DateTimeImmutable $validFor,
    ) {
    }
}
