<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Entity\Invoice;

/**
 * Způsob platby tak, jak ho čte host na dokladu.
 *
 * U platby přijaté předem doplňuje jméno portálu, kterému host zaplatil —
 * bez něj by doklad říkal jen „přes zprostředkovatele" a host by netušil,
 * o kterou platbu jde.
 */
final class PaymentMethodLabeler
{
    public function label(Invoice $invoice): string
    {
        $method = $invoice->getPaymentMethod();
        if (!$method->settledOnIssue()) {
            return $method->label();
        }

        $channel = $invoice->getReservation()->getChannel();

        return $channel->isOta() ? 'přes portál ' . $channel->label() : $method->label();
    }
}
