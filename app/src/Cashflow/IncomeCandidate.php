<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Cashflow;

use App\Entity\Account;
use App\Enum\IncomeSource;

/**
 * Kandidát na příjem rezervace odvozený z jednoho zdroje — než ho IncomeUpserter
 * porovná prioritou s uloženým ReservationIncome.
 */
final readonly class IncomeCandidate
{
    public function __construct(
        public string $amountCzk,
        public IncomeSource $source,
        public ?Account $account,
        public ?\DateTimeImmutable $receivedOn,
    ) {
    }
}
