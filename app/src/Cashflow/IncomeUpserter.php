<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Cashflow;

use App\Currency\CurrencyConverter;
use App\Entity\Account;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Entity\ReservationReceipt;
use App\Enum\AccountType;
use App\Enum\Channel;
use App\Enum\IncomeSource;
use App\Enum\InvoiceType;
use App\Enum\ReceiptOrigin;
use App\Enum\ReservationStatus;
use App\Repository\AccountRepository;
use App\Repository\InvoiceRepository;
use App\Repository\PaymentRepository;
use App\Repository\ReservationReceiptRepository;
use App\Reservation\Event\ReservationFinancialsChangedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Udržuje dílčí přijaté platby rezervace (ReservationReceipt) — jeden řádek na
 * platební událost, každý s vlastním datem přijetí. Rozlišuje kanál:
 *
 * - **Přímá objednávka (web):** reálný příjem = **každá zaplacená faktura** (záloha
 *   dřív, doplatek při příjezdu — dva řádky se svými daty), jinak spárované
 *   bankovní platby. Nezaplaceno = na účtu zatím nic.
 * - **OTA (Airbnb/Booking):** dokud nedorazí výplata, jeden řádek **odhadu net**
 *   (hrubá − provize); po výplatě (Airbnb z mailu, jinak ručně) se nahradí
 *   reálnou částkou.
 *
 * Auto-receipty (faktury/platby/odhad) se při každém recompute synchronizují dle
 * aktuálního stavu; ručně zadaná výplata (`manuallyOverridden`) zůstává a u OTA
 * potlačí automatický odhad.
 */
class IncomeUpserter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ReservationReceiptRepository $receipts,
        private readonly PaymentRepository $payments,
        private readonly InvoiceRepository $invoices,
        private readonly AccountRepository $accounts,
        private readonly CurrencyConverter $converter,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function recompute(Reservation $reservation): void
    {
        $existing = $this->receipts->findForReservation($reservation);

        // Nedotažená rezervace (needs_details) ještě není příjem — smažeme vše.
        if ($reservation->getStatus() === ReservationStatus::NEEDS_DETAILS) {
            $this->removeAll($existing);

            return;
        }

        $targets = $this->resolveTargets($reservation, $existing);

        // Zrušená rezervace vede jen REÁLNĚ přijaté peníze (zaplacená faktura /
        // bankovní platba = nevrácená záloha, storno-poplatek) — ne odhad budoucí
        // výplaty, ta u zrušeného pobytu nedorazí.
        if ($reservation->getStatus() === ReservationStatus::CANCELLED) {
            $targets = array_values(array_filter(
                $targets,
                static fn (ReceiptTarget $t): bool => $t->source !== IncomeSource::ESTIMATE,
            ));
        }

        $this->sync($reservation, $existing, $targets);
    }

    /**
     * Ruční záznam reálné OTA výplaty — přepíše odhad a zamkne se proti dalšímu
     * auto-přepočtu. Použije se pro Booking (payout mail nechodí) i pro Airbnb,
     * když výplatu zadáváš ručně.
     */
    public function recordManualPayout(Reservation $reservation, string $amountCzk, \DateTimeImmutable $receivedOn): void
    {
        $existing = $this->receipts->findForReservation($reservation);

        // Ruční výplata je konečná pravda o příjmu rezervace → nahrazuje veškeré
        // automatické receipty (odhad, výplata i případné faktury), aby zůstal
        // jediný řádek a stav účtu se nepřeúčtoval dvakrát.
        foreach ($existing as $receipt) {
            if (!$receipt->isManuallyOverridden()) {
                $this->em->remove($receipt);
            }
        }

        $manual = $this->receipts->findOneByOrigin($reservation, ReceiptOrigin::MANUAL, 0)
            ?? new ReservationReceipt($reservation, $amountCzk, IncomeSource::OTA_PAYOUT, ReceiptOrigin::MANUAL);
        $manual->setAmountCzk($amountCzk);
        $manual->setSource(IncomeSource::OTA_PAYOUT);
        $manual->setAccount($this->bankAccount());
        $manual->setReceivedOn($receivedOn);
        $manual->setManuallyOverridden(true);
        $this->em->persist($manual);
        $this->em->flush();
    }

    /**
     * Ručně zaznamenaná platba hosta (hotovost, převod, záloha bez faktury) u
     * přímé/web rezervace. Přidává se vedle případných faktur — host může platit
     * víc splátkami — a je chráněná proti auto-přepočtu. Reálný příjem do cashflow.
     */
    public function recordManualPayment(Reservation $reservation, string $amountCzk, \DateTimeImmutable $receivedOn): ReservationReceipt
    {
        $nextId = 1;
        foreach ($this->receipts->findForReservation($reservation) as $receipt) {
            if ($receipt->getOriginType() === ReceiptOrigin::MANUAL_PAYMENT) {
                $nextId = max($nextId, $receipt->getOriginId() + 1);
            }
        }

        $payment = new ReservationReceipt($reservation, $amountCzk, IncomeSource::MANUAL_PAYMENT, ReceiptOrigin::MANUAL_PAYMENT, $nextId);
        $payment->setAccount($this->bankAccount());
        $payment->setReceivedOn($receivedOn);
        $payment->setManuallyOverridden(true);
        $this->em->persist($payment);
        $this->em->flush();

        // Platba mohla doplatek srovnat → připomínka doplatku je zbytečná.
        $this->dispatcher->dispatch(new ReservationFinancialsChangedEvent($reservation));

        return $payment;
    }

    /**
     * Synchronizuje automatické receipty na cílový stav: smaže osiřelé (co už
     * nemá zdroj) a založí/aktualizuje ostatní. Ruční záznamy nechává být.
     *
     * @param ReservationReceipt[] $existing
     * @param ReceiptTarget[]      $targets
     */
    private function sync(Reservation $reservation, array $existing, array $targets): void
    {
        $byKey = [];
        foreach ($existing as $receipt) {
            $byKey[$receipt->originKey()] = $receipt;
        }
        $wanted = [];
        foreach ($targets as $target) {
            $wanted[$target->key()] = true;
        }

        foreach ($existing as $receipt) {
            if ($receipt->isManuallyOverridden()) {
                continue;
            }
            if (!isset($wanted[$receipt->originKey()])) {
                $this->em->remove($receipt);
            }
        }

        foreach ($targets as $target) {
            $receipt = $byKey[$target->key()] ?? null;
            if ($receipt === null) {
                $receipt = new ReservationReceipt($reservation, $target->amountCzk, $target->source, $target->originType, $target->originId);
            } elseif ($receipt->isManuallyOverridden()) {
                continue;
            } else {
                $receipt->setAmountCzk($target->amountCzk);
                $receipt->setSource($target->source);
            }
            $receipt->setAccount($target->account);
            $receipt->setReceivedOn($target->receivedOn);
            $this->em->persist($receipt);
        }

        $this->em->flush();
    }

    /**
     * @param ReservationReceipt[] $existing
     *
     * @return ReceiptTarget[]
     */
    private function resolveTargets(Reservation $reservation, array $existing): array
    {
        return $this->isOta($reservation)
            ? $this->otaTargets($reservation, $existing)
            : $this->directTargets($reservation);
    }

    /**
     * OTA: reálná výplata (Airbnb parsovaná), jinak odhad net = hrubá − provize.
     * Ruční výplata (MANUAL) auto-větev potlačí.
     *
     * @param ReservationReceipt[] $existing
     *
     * @return ReceiptTarget[]
     */
    private function otaTargets(Reservation $reservation, array $existing): array
    {
        foreach ($existing as $receipt) {
            if ($receipt->isManuallyOverridden()) {
                return [];
            }
        }

        if ($reservation->getPayoutAmount() !== null) {
            return [new ReceiptTarget(
                ReceiptOrigin::PAYOUT,
                0,
                $reservation->getPayoutAmount(),
                IncomeSource::OTA_PAYOUT,
                $this->bankAccount(),
                $reservation->getPayoutSentAt(),
            )];
        }

        $gross = $this->grossCzk($reservation);
        if ($gross === null) {
            return [];
        }
        $net = bcsub($gross, $this->commissionCzk($reservation), 2);

        return [new ReceiptTarget(ReceiptOrigin::ESTIMATE, 0, $net, IncomeSource::ESTIMATE, $this->bankAccount(), $reservation->getCheckOut())];
    }

    /**
     * Přímá objednávka: každá zaplacená faktura je dílčí příjem (FULL má
     * přednost jako jediná; jinak záloha + konečná zvlášť). Bez zaplacené
     * faktury spadneme na spárované bankovní platby.
     *
     * @return ReceiptTarget[]
     */
    private function directTargets(Reservation $reservation): array
    {
        $invoices = $this->invoices->findForReservation($reservation);

        foreach ($invoices as $invoice) {
            if ($invoice->getType() === InvoiceType::FULL && $invoice->isPaid()) {
                $amount = $this->invoiceCzk($reservation, $invoice);
                if ($amount !== null) {
                    return [$this->invoiceTarget($reservation, $invoice, $amount)];
                }
            }
        }

        $targets = [];
        foreach ($invoices as $invoice) {
            if (!$invoice->isPaid() || $invoice->getType() === InvoiceType::FULL) {
                continue;
            }
            $amount = $this->invoiceCzk($reservation, $invoice);
            if ($amount !== null) {
                $targets[] = $this->invoiceTarget($reservation, $invoice, $amount);
            }
        }
        if ($targets !== []) {
            return $targets;
        }

        foreach ($this->payments->findByReservation($reservation) as $payment) {
            if ($payment->getCurrency() !== 'CZK') {
                continue;
            }
            $targets[] = new ReceiptTarget(
                ReceiptOrigin::PAYMENT,
                (int) $payment->getId(),
                $payment->getAmount(),
                IncomeSource::BANK_PAYMENT,
                $this->bankAccount(),
                $payment->getReceivedAt()->setTime(0, 0),
            );
        }

        return $targets;
    }

    private function invoiceTarget(Reservation $reservation, Invoice $invoice, string $amountCzk): ReceiptTarget
    {
        return new ReceiptTarget(
            ReceiptOrigin::INVOICE,
            (int) $invoice->getId(),
            $amountCzk,
            IncomeSource::PAID_INVOICE,
            $this->accountForInvoice($invoice),
            $invoice->getPaidAt(),
        );
    }

    /**
     * @param ReservationReceipt[] $receipts
     */
    private function removeAll(array $receipts): void
    {
        if ($receipts === []) {
            return;
        }
        foreach ($receipts as $receipt) {
            $this->em->remove($receipt);
        }
        $this->em->flush();
    }

    private function isOta(Reservation $reservation): bool
    {
        return \in_array($reservation->getChannel(), [Channel::AIRBNB, Channel::BOOKING], true);
    }

    /** Hrubá částka pobytu v CZK (cena hosta) — základ pro odhad OTA výplaty. */
    private function grossCzk(Reservation $reservation): ?string
    {
        return $this->converter->toCzk($reservation->getPriceTotal(), $reservation->getPriceCurrency(), $reservation->getVatCnbRate());
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

        return $this->converter->toCzk($commission, $reservation->getCommissionCurrency(), $reservation->getVatCnbRate()) ?? '0.00';
    }

    /** Částka faktury v CZK; EUR přes kurz faktury, fallback uložený ČNB kurz rezervace. */
    private function invoiceCzk(Reservation $reservation, Invoice $invoice): ?string
    {
        return $this->converter->toCzk($invoice->getTotalAmount(), $invoice->getCurrency(), $invoice->getExchangeRate() ?? $reservation->getVatCnbRate());
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
