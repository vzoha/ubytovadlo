<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Email\Handler;

use App\Cashflow\IncomeUpserter;
use App\Email\AirbnbPayoutParser;
use App\Email\Dto\AirbnbPayoutData;
use App\Email\EmailMessage;
use App\Entity\EmailLog;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\ConnectorType;
use App\Formatting\Money;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;

/**
 * Airbnb notifikace o výplatě → napáruje reálnou částku a datum odeslání na
 * rezervaci podle potvrzujícího kódu a nastaví datum úhrady faktury hostovi.
 */
final class AirbnbPayoutHandler implements EmailHandler
{
    public function __construct(
        private readonly AirbnbPayoutParser $parser,
        private readonly ReservationRepository $reservations,
        private readonly InvoiceRepository $invoices,
        private readonly IncomeUpserter $incomeUpserter,
    ) {
    }

    public function supports(EmailMessage $email): bool
    {
        return $this->parser->supports($email);
    }

    public function connectorType(): ConnectorType
    {
        return ConnectorType::AIRBNB;
    }

    public function handle(EmailMessage $email, EmailLog $log): void
    {
        $data = $this->parser->parse($email);
        $reservation = $this->apply($data);
        if ($reservation !== null) {
            $log->markProcessed($reservation);
        } else {
            $log->markIgnored(sprintf('Payout for unknown reservation %s', $data->confirmationCode));
        }
    }

    /**
     * Napáruje reálnou Airbnb výplatu na rezervaci podle potvrzujícího kódu.
     * Uloží částku a datum odeslání a — pokud už existuje faktura hostovi —
     * nastaví na ní datum úhrady na den odeslání výplaty (reálný příjem peněz).
     * Vrací null, pokud rezervace zatím v DB není (potvrzovací e-mail nedorazil).
     */
    private function apply(AirbnbPayoutData $data): ?Reservation
    {
        $reservation = $this->reservations->findByExternalId(Channel::AIRBNB, $data->confirmationCode);
        if ($reservation === null) {
            return null;
        }

        $reservation->setPayoutAmount(Money::normalize($data->payoutAmount));
        $reservation->setPayoutSentAt($data->payoutSentAt);
        if ($data->payoutReference !== null) {
            $reservation->setPayoutReference($data->payoutReference);
        }

        // Datum úhrady na faktuře = den, kdy Airbnb peníze odeslal. Nastavujeme
        // jen na dosud neuhrazené faktury, ruční označení nepřepisujeme.
        foreach ($this->invoices->findForReservation($reservation) as $invoice) {
            if (!$invoice->isPaid()) {
                $invoice->setPaidAt($data->payoutSentAt);
            }
        }

        // Výplata (net po provizi) je reálný příjem na účet — přepočítej ReservationIncome.
        $this->incomeUpserter->recompute($reservation);

        return $reservation;
    }
}
