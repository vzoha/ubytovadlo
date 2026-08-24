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
use App\Entity\InvoiceLine;
use App\Formatting\Money;

/**
 * Zaokrouhlení faktury placené hotově. V hotovosti se platí celými korunami,
 * proto se rozdíl na celou korunu propíše jako samostatný řádek faktury a
 * srovná s ním částku k úhradě. Řádek je bez sazby DPH — rozdíl ze
 * zaokrouhlení daň nenese (§ 36 odst. 5 ZDPH), takže do rekapitulace daně
 * ({@see InvoiceVatRecap}) nevstupuje.
 */
final class CashRounding
{
    /** Popis řádku, podle kterého se zaokrouhlení na faktuře pozná. */
    public const LINE_DESCRIPTION = 'Zaokrouhlení';

    /** Zaokrouhlí částku k úhradě na celé koruny a rozdíl přidá jako řádek faktury. */
    public static function applyTo(Invoice $invoice): void
    {
        self::stripFrom($invoice);

        $total = $invoice->getTotalAmount();
        $difference = bcsub(Money::normalize(round((float) $total)), $total, 2);
        if (bccomp($difference, '0', 2) === 0) {
            return;
        }

        $line = new InvoiceLine(self::LINE_DESCRIPTION, $difference);
        $line->setPosition($invoice->getLines()->count());
        $invoice->addLine($line);
        $invoice->setTotalAmount(bcadd($total, $difference, 2));
    }

    /** Odebere řádek se zaokrouhlením — částka k úhradě se vrátí na haléře. */
    public static function stripFrom(Invoice $invoice): void
    {
        foreach ($invoice->getLines() as $line) {
            if ($line->getDescription() !== self::LINE_DESCRIPTION) {
                continue;
            }
            $invoice->removeLine($line);
            $invoice->setTotalAmount(bcsub($invoice->getTotalAmount(), $line->getTotalPrice(), 2));
        }
    }
}
