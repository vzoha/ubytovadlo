<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Service\Electricity;

final readonly class ElectricityCost
{
    public function __construct(
        public float $vtCzk,
        public float $ntCzk,
        public float $totalCzk,
        public float $vtRate,
        public float $ntRate,
    ) {
    }
}
