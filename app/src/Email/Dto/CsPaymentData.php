<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\Dto;

/**
 * Strukturovaná data z notifikace České spořitelny "Přišla platba".
 * Amount je normalizovaný řetězec ("1000.00"), aby šel přímo porovnat s částkami faktur.
 */
final class CsPaymentData
{
    public function __construct(
        public readonly bool $incoming,
        public readonly string $amount,
        public readonly string $currency,
        public readonly ?string $variableSymbol,
        public readonly ?string $constantSymbol,
        public readonly ?string $counterpartyAccount,
        public readonly \DateTimeImmutable $receivedAt,
    ) {
    }
}
