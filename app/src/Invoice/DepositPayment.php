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
 * Podklad pro platbu zálohy hostem před vystavením faktury: kolik, kam, s jakým
 * variabilním symbolem a do kdy. `spayd` je řetězec pro QR Platbu (null, když
 * instance nemá IBAN, a QR se tedy vygenerovat nedá).
 */
final readonly class DepositPayment
{
    public function __construct(
        public string $amount,
        public string $variableSymbol,
        public string $bankAccount,
        public ?string $iban,
        public \DateTimeImmutable $dueDate,
        public ?string $spayd,
    ) {
    }
}
