<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Enum\PaymentStatus;

/** Výsledek dopočtu doplatku — vše v CZK. */
final readonly class BalanceResult
{
    public function __construct(
        public float $total,
        public float $paid,
        public float $remaining,
    ) {
    }

    public function isSettled(): bool
    {
        return $this->remaining <= 0.0;
    }

    public function hasAnyPayment(): bool
    {
        return $this->paid > 0.0;
    }

    public function status(): PaymentStatus
    {
        return PaymentStatus::fromAmounts($this->total, $this->paid);
    }
}
