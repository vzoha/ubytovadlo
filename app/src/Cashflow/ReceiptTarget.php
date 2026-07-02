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
use App\Entity\ReservationReceipt;
use App\Enum\IncomeSource;
use App\Enum\ReceiptOrigin;

/**
 * Cílový stav jedné dílčí platby (ReservationReceipt) odvozený z jednoho zdroje
 * (faktura, platba, výplata, odhad). IncomeUpserter podle dvojice
 * (originType, originId) rozhodne, jestli receipt založit nebo aktualizovat.
 */
final readonly class ReceiptTarget
{
    public function __construct(
        public ReceiptOrigin $originType,
        public int $originId,
        public string $amountCzk,
        public IncomeSource $source,
        public ?Account $account,
        public ?\DateTimeImmutable $receivedOn,
    ) {
    }

    public function key(): string
    {
        return ReservationReceipt::makeOriginKey($this->originType, $this->originId);
    }
}
