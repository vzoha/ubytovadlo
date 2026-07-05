<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Payment;

use App\Email\Dto\CsPaymentData;
use App\Email\EmailMessage;
use App\Entity\Invoice;
use App\Entity\Payment;
use App\Entity\Reservation;
use App\Enum\InvoiceType;
use App\Enum\PaymentSource;
use App\Invoice\DepositConfig;
use App\Invoice\InvoiceService;
use App\Payment\Event\PaymentSettledEvent;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationRepository;
use App\Timeline\ReservationActionPlanner;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Zaeviduje příchozí platbu a napáruje ji na rezervaci podle variabilního symbolu:
 *  1) VS = číslo faktury → uhradí danou fakturu (host platil z QR na faktuře).
 *  2) VS = kód rezervace (MotoPress booking ID) → typicky záloha web klasiky:
 *     vystaví (chybí-li) a označí zálohovou fakturu uhrazenou.
 *
 * Platbu eviduje i když ji nelze spárovat — k pozdější ruční reconciliation.
 * Vystavení faktury přeskočí, pokud rezervaci ještě chybí údaje hosta (zaeviduje
 * jen platbu, fakturu doplní majitelka ručně).
 */
class PaymentProcessor
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceRepository $invoices,
        private readonly ReservationRepository $reservations,
        private readonly InvoiceService $invoiceService,
        private readonly ReservationActionPlanner $planner,
        private readonly EventDispatcherInterface $dispatcher,
        private readonly DepositConfig $depositConfig,
    ) {
    }

    public function process(CsPaymentData $data, EmailMessage $email): PaymentResult
    {
        if (!$data->incoming) {
            return PaymentResult::unmatched('Odchozí platba — ignorováno.');
        }

        $payment = new Payment(
            PaymentSource::CS_EMAIL,
            $data->amount,
            $data->currency,
            $data->receivedAt,
            $email->messageId,
        );
        $payment->setVariableSymbol($data->variableSymbol);
        $payment->setConstantSymbol($data->constantSymbol);
        $payment->setCounterpartyAccount($data->counterpartyAccount);
        $this->em->persist($payment);

        $vs = $data->variableSymbol;
        if ($vs === null) {
            return PaymentResult::unmatched('Platba bez variabilního symbolu — nelze spárovat.');
        }

        $invoice = $this->invoices->findOneByVariableSymbol($vs);
        if ($invoice !== null) {
            $reservation = $invoice->getReservation();
            $payment->setReservation($reservation);
            $payment->setInvoice($invoice);
            $this->settle($invoice, $data);

            return $this->matched($payment, $reservation);
        }

        $reservation = $this->reservations->findByPaymentVariableSymbol($vs);
        if ($reservation === null) {
            return PaymentResult::unmatched(sprintf('Platba VS %s bez navázané rezervace.', $vs));
        }

        $payment->setReservation($reservation);
        $this->applyDeposit($payment, $reservation, $data);

        return $this->matched($payment, $reservation);
    }

    /**
     * Dotáhne rezervaci po spárování platby: naplánuje akce a vyšle událost
     * pro volitelné konektory (push do MotoPressu apod.).
     */
    private function matched(Payment $payment, Reservation $reservation): PaymentResult
    {
        $this->planner->planFor($reservation);
        $this->dispatcher->dispatch(new PaymentSettledEvent($payment));

        return PaymentResult::matched($reservation);
    }

    /**
     * Web klasika: platba ve výši zálohy → vystav (chybí-li) a uhraď zálohovou fakturu.
     * Jiný tok nebo jiná částka (doplatek/přeplatek) se nevystavuje automaticky.
     */
    private function applyDeposit(Payment $payment, Reservation $reservation, CsPaymentData $data): void
    {
        // Jen toky se zálohou a jen když je záloha zapnutá (ne „bez zálohy").
        if (!$this->depositConfig->appliesTo($reservation->getBillingMode())) {
            return;
        }
        // Záloha web klasiky je vždy v CZK; cizí měnu sem nepouštíme. Očekávaná výše
        // se počítá z ceny rezervace (fixní částka, nebo procento).
        $expected = $this->depositConfig->computeAmount($reservation->getPriceTotal());
        if ($data->currency !== 'CZK' || $expected === null || $expected !== $data->amount) {
            return;
        }

        $deposit = $this->invoices->findFirstByReservationAndType($reservation, InvoiceType::DEPOSIT);
        if ($deposit === null) {
            if (!$this->canIssue($reservation)) {
                return;
            }
            $deposit = $this->invoiceService->issueDeposit($reservation, $data->receivedAt);
        }

        $this->settle($deposit, $data);
        $payment->setInvoice($deposit);
    }

    /**
     * Označí fakturu uhrazenou jen tehdy, sedí-li měna platby s měnou faktury —
     * EUR platba nesmí uhradit CZK fakturu (a naopak). Při neshodě zůstává platba
     * jen zaevidovaná a navázaná, úhradu vyřeší majitelka ručně.
     */
    private function settle(Invoice $invoice, CsPaymentData $data): void
    {
        if (!$invoice->isPaid() && $invoice->getCurrency() === $data->currency) {
            $this->invoiceService->markPaid($invoice, $data->receivedAt);
        }
    }

    private function canIssue(Reservation $reservation): bool
    {
        $mode = $reservation->getBillingMode();

        return ($reservation->getGuestName() ?? '') !== ''
            && $mode !== null && $mode->isInvoiced();
    }
}
