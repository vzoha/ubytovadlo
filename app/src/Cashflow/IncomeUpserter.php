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
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationIncome;
use App\Enum\AccountType;
use App\Enum\Channel;
use App\Enum\IncomeSource;
use App\Enum\InvoiceType;
use App\Enum\ReservationStatus;
use App\Repository\AccountRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Repository\ReservationIncomeRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Udržuje reálně přijatý příjem rezervace (ReservationIncome) upsertem dle
 * priority zdroje. Rozlišuje kanál:
 *
 * - **Přímá objednávka (web):** reálný příjem = zaplacená faktura / bankovní
 *   kredit. Nezaplaceno = na účtu zatím nic.
 * - **OTA (Airbnb/Booking):** faktura vystavená v průběhu pobytu je jen doklad;
 *   příjem se vede jako **odhad net (hrubá − provize)**, dokud nedorazí reálná
 *   výplata — Airbnb automaticky z výplatního mailu, jinak ručně
 *   (`recordManualPayout`) — která odhad přepíše.
 *
 * Jeden záznam na rezervaci → stejná výplata se nikdy nezapočte dvakrát. Ručně
 * zadaná výplata (`manuallyOverridden`) se auto-přepočtem nemění.
 */
class IncomeUpserter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationIncomeRepository $incomes,
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly AccountRepository $accounts,
    ) {
    }

    public function recompute(Reservation $reservation): void
    {
        $existing = $this->incomes->findForReservation($reservation);

        // Zrušené a nedotažené (needs_details) rezervace nejsou příjem — případný
        // dřívější záznam odstraníme.
        if (\in_array($reservation->getStatus(), [ReservationStatus::CANCELLED, ReservationStatus::NEEDS_DETAILS], true)) {
            if ($existing !== null) {
                $this->em->remove($existing);
                $this->em->flush();
            }

            return;
        }

        if ($existing !== null && $existing->isManuallyOverridden()) {
            return;
        }

        $candidate = $this->resolveCandidate($reservation);
        if ($candidate === null) {
            return;
        }

        $this->store($reservation, $existing, $candidate, manual: false);
    }

    /**
     * Ruční záznam reálné OTA výplaty — přepíše odhad a zamkne příjem proti
     * dalšímu auto-přepočtu. Použije se pro Booking (payout mail nechodí) i pro
     * Airbnb, když výplatu zadáváš ručně.
     */
    public function recordManualPayout(Reservation $reservation, string $amountCzk, \DateTimeImmutable $receivedOn): void
    {
        $existing = $this->incomes->findForReservation($reservation);
        $candidate = new IncomeCandidate($amountCzk, IncomeSource::OTA_PAYOUT, $this->bankAccount(), $receivedOn);
        $this->store($reservation, $existing, $candidate, manual: true);
    }

    private function store(Reservation $reservation, ?ReservationIncome $existing, IncomeCandidate $candidate, bool $manual): void
    {
        if ($existing === null) {
            $income = new ReservationIncome($reservation, $candidate->amountCzk, $candidate->source);
        } elseif ($manual || $candidate->source->priority() >= $existing->getSource()->priority()) {
            $income = $existing;
            $income->setAmountCzk($candidate->amountCzk);
            $income->setSource($candidate->source);
        } else {
            return;
        }

        $income->setAccount($candidate->account);
        $income->setReceivedOn($candidate->receivedOn);
        if ($manual) {
            $income->setManuallyOverridden(true);
        }
        $this->em->persist($income);
        $this->em->flush();
    }

    private function resolveCandidate(Reservation $reservation): ?IncomeCandidate
    {
        return $this->isOta($reservation)
            ? $this->resolveOta($reservation)
            : $this->resolveDirect($reservation);
    }

    /** OTA: reálná výplata (Airbnb parsovaná), jinak odhad net = hrubá − provize. */
    private function resolveOta(Reservation $reservation): ?IncomeCandidate
    {
        if ($reservation->getPayoutAmount() !== null) {
            return new IncomeCandidate(
                $reservation->getPayoutAmount(),
                IncomeSource::OTA_PAYOUT,
                $this->bankAccount(),
                $reservation->getPayoutSentAt(),
            );
        }

        $gross = $this->grossCzk($reservation);
        if ($gross === null) {
            return null;
        }
        $commission = $this->commissionCzk($reservation);
        $net = bcsub($gross, $commission, 2);

        return new IncomeCandidate($net, IncomeSource::ESTIMATE, $this->bankAccount(), $reservation->getCheckOut());
    }

    /** Přímá objednávka: reálný příjem = zaplacená faktura, jinak spárovaný bankovní kredit. */
    private function resolveDirect(Reservation $reservation): ?IncomeCandidate
    {
        $paidInvoice = $this->resolvePaidInvoices($reservation);
        if ($paidInvoice !== null) {
            return $paidInvoice;
        }

        $sum = '0.00';
        $latest = null;
        foreach ($this->payments->findByReservation($reservation) as $payment) {
            if ($payment->getCurrency() !== 'CZK') {
                continue;
            }
            $sum = bcadd($sum, $payment->getAmount(), 2);
            $date = $payment->getReceivedAt()->setTime(0, 0);
            if ($latest === null || $date > $latest) {
                $latest = $date;
            }
        }
        if (bccomp($sum, '0.00', 2) > 0) {
            return new IncomeCandidate($sum, IncomeSource::BANK_PAYMENT, $this->bankAccount(), $latest);
        }

        return null;
    }

    private function resolvePaidInvoices(Reservation $reservation): ?IncomeCandidate
    {
        $invoices = $this->invoices->findForReservation($reservation);

        foreach ($invoices as $invoice) {
            if ($invoice->getType() === InvoiceType::FULL && $invoice->isPaid()) {
                $amount = $this->invoiceCzk($reservation, $invoice);
                if ($amount !== null) {
                    return new IncomeCandidate($amount, IncomeSource::PAID_INVOICE, $this->accountForInvoice($invoice), $invoice->getPaidAt());
                }
            }
        }

        $sum = '0.00';
        $latestPaidAt = null;
        $account = null;
        foreach ($invoices as $invoice) {
            if (!$invoice->isPaid() || $invoice->getType() === InvoiceType::FULL) {
                continue;
            }
            $amount = $this->invoiceCzk($reservation, $invoice);
            if ($amount === null) {
                continue;
            }
            $sum = bcadd($sum, $amount, 2);
            $paidAt = $invoice->getPaidAt();
            if ($paidAt !== null && ($latestPaidAt === null || $paidAt > $latestPaidAt)) {
                $latestPaidAt = $paidAt;
                $account = $this->accountForInvoice($invoice);
            }
        }
        if (bccomp($sum, '0.00', 2) > 0) {
            return new IncomeCandidate($sum, IncomeSource::PAID_INVOICE, $account ?? $this->bankAccount(), $latestPaidAt);
        }

        return null;
    }

    private function isOta(Reservation $reservation): bool
    {
        return \in_array($reservation->getChannel(), [Channel::AIRBNB, Channel::BOOKING], true);
    }

    /** Hrubá částka pobytu v CZK (cena hosta) — základ pro odhad OTA výplaty. */
    private function grossCzk(Reservation $reservation): ?string
    {
        $price = $reservation->getPriceTotal();
        if ($price === null) {
            return null;
        }
        if ($reservation->getPriceCurrency() === 'CZK') {
            return $price;
        }
        $rate = $reservation->getVatCnbRate();

        return $rate !== null ? bcmul($price, $rate, 2) : null;
    }

    /** Provize OTA v CZK (základ pro net). Bez známé provize = 0. */
    private function commissionCzk(Reservation $reservation): string
    {
        if ($reservation->getVatBaseCzk() !== null) {
            return $reservation->getVatBaseCzk();
        }
        $commission = $reservation->getCommissionAmount();
        if ($commission === null) {
            return '0.00';
        }
        if ($reservation->getCommissionCurrency() === 'CZK') {
            return $commission;
        }
        $rate = $reservation->getVatCnbRate();

        return $rate !== null ? bcmul($commission, $rate, 2) : '0.00';
    }

    /** Částka faktury v CZK; EUR přes kurz faktury, fallback uložený ČNB kurz rezervace. */
    private function invoiceCzk(Reservation $reservation, Invoice $invoice): ?string
    {
        if ($invoice->getCurrency() === 'CZK') {
            return $invoice->getTotalAmount();
        }
        $rate = $invoice->getExchangeRate() ?? $reservation->getVatCnbRate();

        return $rate !== null ? bcmul($invoice->getTotalAmount(), $rate, 2) : null;
    }

    private function accountForInvoice(Invoice $invoice): ?Account
    {
        return str_contains(mb_strtolower($invoice->getPaymentMethod()), 'hotov')
            ? $this->accounts->findDefaultByType(AccountType::CASH)
            : $this->bankAccount();
    }

    private function bankAccount(): ?Account
    {
        return $this->accounts->findDefaultByType(AccountType::BANK);
    }
}
