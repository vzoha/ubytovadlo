<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Payment\Event;

use App\Entity\Payment;

/**
 * Vyslána, jakmile je příchozí platba zaevidována a napárována na rezervaci.
 * Jádro tím jen oznamuje fakt "peníze dorazily" — neví, kdo (a jestli vůbec)
 * naslouchá. Volitelné konektory (např. push stavu do MotoPressu) na ni reagují;
 * instance bez nich nemá žádný listener a událost zůstane bez efektu.
 */
final class PaymentSettledEvent
{
    public function __construct(
        public readonly Payment $payment,
    ) {
    }
}
