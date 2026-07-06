<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Entity\Reservation;

/**
 * Sestaví podklad pro platbu zálohy (DepositPayment) k rezervaci, nebo null když
 * záloha nedává smysl — tok bez zálohy, cizí měna, nevyčíslitelná výše nebo chybí
 * variabilní symbol. Sdílí ho žádost o zálohu (e-mail hostovi) i QR endpoint.
 */
class DepositPaymentBuilder
{
    public function __construct(
        private readonly DepositConfig $depositConfig,
        private readonly IssuerProfileProvider $issuers,
        private readonly SpaydGenerator $spayd,
    ) {
    }

    public function forReservation(Reservation $reservation, ?\DateTimeImmutable $issuedAt = null): ?DepositPayment
    {
        // Záloha se bere jen u toku, který ji vyžaduje, a jen v CZK (cizí měnu neřešíme).
        if (!$this->depositConfig->appliesTo($reservation->getBillingMode()) || $reservation->getPriceCurrency() !== 'CZK') {
            return null;
        }

        $amount = $this->depositConfig->computeAmount($reservation->getPriceTotal());
        $variableSymbol = $reservation->getPaymentVariableSymbol();
        if ($amount === null || $variableSymbol === null) {
            return null;
        }

        $issuer = $this->issuers->current();
        // Splatnost počítáme od vzniku rezervace (ne od „dnes"), aby datum v e-mailu
        // sedělo s datem v QR kódu i při pozdějším otevření/načtení QR.
        $issuedAt ??= $reservation->getCreatedAt();
        $dueDate = $issuedAt->modify(sprintf('+%d days', $this->depositConfig->dueDays()))->setTime(0, 0);

        $iban = $this->iban($issuer);
        $spayd = $iban !== null
            ? $this->spayd->generate($iban, $amount, 'CZK', $variableSymbol, 'Zaloha rezervace ' . $variableSymbol, $dueDate)
            : null;

        return new DepositPayment($amount, $variableSymbol, $issuer->bankAccount, $iban, $dueDate, $spayd);
    }

    /** IBAN dodavatele — přednostně uložený, jinak dopočtený z čísla účtu. */
    private function iban(IssuerProfile $issuer): ?string
    {
        if (trim($issuer->bankAccountIban) !== '') {
            return $issuer->bankAccountIban;
        }
        if (trim($issuer->bankAccount) === '') {
            return null;
        }

        try {
            return $this->spayd->bankAccountToIban($issuer->bankAccount);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
