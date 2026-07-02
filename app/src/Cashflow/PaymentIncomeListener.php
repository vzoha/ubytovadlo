<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Cashflow;

use App\Payment\Event\PaymentSettledEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Po spárování bankovní platby přepočítá reálný příjem rezervace (ReservationIncome).
 * Reaguje na tutéž událost jako MotoPress push — jádro jen oznámí „peníze dorazily".
 */
#[AsEventListener]
final class PaymentIncomeListener
{
    public function __construct(
        private readonly IncomeUpserter $incomeUpserter,
    ) {
    }

    public function __invoke(PaymentSettledEvent $event): void
    {
        $reservation = $event->payment->getReservation();
        if ($reservation !== null) {
            $this->incomeUpserter->recompute($reservation);
        }
    }
}
