<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Profit;

use App\Currency\CurrencyConverter;
use App\Entity\Cleaning;
use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Enum\InvoiceType;
use App\Enum\ReservationStatus;
use App\Formatting\Money;
use App\Invoice\TaxProfileConfig;
use App\Repository\CleaningRepository;
use App\Repository\InvoiceRepository;
use App\Repository\ReservationReceiptRepository;
use App\Repository\SettingRepository;
use App\Service\Electricity\ElectricityCostCalculator;

/**
 * On-the-fly výpočet zisku rezervace — žádné DB sloupce, vše se odvozuje
 * z existujících dat (faktury, ČNB kurz, elektroměr, úklid, DPH modul).
 *
 * Příjem v CZK (bez dvojího započtení záloha + konečná):
 *   1. FULL faktura → její částka,
 *   2. FINAL faktura → FINAL + její zálohová parent faktura,
 *   3. jen DEPOSIT → ignoruje se (není celý příjem) → odhad z ceny,
 *   4. bez faktury: cena v CZK přímo; EUR (Booking) přepočtem uloženým
 *      ČNB kurzem k DUZP provize → označeno jako odhad.
 *
 * Zrušená rezervace je výjimka: pobyt neproběhl, takže příjmem jsou jen reálně
 * přijaté peníze (nevrácená záloha, storno poplatek) a výdaje, které se vážou
 * na pobyt (úklid, elektřina, rekreační poplatek), nevznikly.
 */
final class ReservationProfitCalculator
{
    /** Setting key pro sazbu rekreačního poplatku (Kč / dospělý / noc), default 15. Děti jsou osvobozeny. */
    public const RECREATION_FEE_KEY = 'recreation_fee.per_adult_night';
    public const RECREATION_FEE_DEFAULT = 15;

    public function __construct(
        private readonly ElectricityCostCalculator $electricityCost,
        private readonly CleaningRepository $cleanings,
        private readonly InvoiceRepository $invoices,
        private readonly SettingRepository $settings,
        private readonly ReservationReceiptRepository $receipts,
        private readonly CurrencyConverter $converter,
        private readonly TaxProfileConfig $taxProfile,
    ) {
    }

    public function calculate(Reservation $reservation): ReservationProfit
    {
        return $this->calculateBatch([$reservation])[$reservation->getId()];
    }

    /**
     * Batch varianta pro roční přehled — prefetch úklidů a faktur, žádné N+1.
     *
     * @param Reservation[] $reservations
     *
     * @return array<int, ReservationProfit> klíč = ID rezervace
     */
    public function calculateBatch(array $reservations): array
    {
        $cleaningsByReservation = $this->cleanings->findByReservations($reservations);
        $invoicesByReservation = $this->invoices->findGroupedByReservations($reservations);
        $feePerAdultNight = $this->settings->getInt(self::RECREATION_FEE_KEY, self::RECREATION_FEE_DEFAULT);
        $realMoney = $this->receipts->sumRealMoneyForReservations($this->cancelledIds($reservations));

        $result = [];
        foreach ($reservations as $reservation) {
            $id = (int) $reservation->getId();
            $result[$id] = $this->calculateOne(
                $reservation,
                $cleaningsByReservation[$id] ?? null,
                $invoicesByReservation[$id] ?? [],
                $feePerAdultNight,
                $realMoney[$id] ?? 0.0,
            );
        }

        return $result;
    }

    /**
     * Rezervace, u kterých má smysl ptát se na reálně přijaté peníze.
     *
     * @param Reservation[] $reservations
     *
     * @return int[]
     */
    private function cancelledIds(array $reservations): array
    {
        $ids = [];
        foreach ($reservations as $reservation) {
            if ($reservation->getStatus() === ReservationStatus::CANCELLED && $reservation->getId() !== null) {
                $ids[] = $reservation->getId();
            }
        }

        return $ids;
    }

    /**
     * @param Invoice[] $invoices
     */
    private function calculateOne(Reservation $r, ?Cleaning $cleaning, array $invoices, int $feePerAdultNight, float $realMoneyCzk): ReservationProfit
    {
        $nights = $r->getCheckOut() !== null
            ? $r->getCheckIn()->diff($r->getCheckOut())->days
            : 0;

        if ($r->getStatus() === ReservationStatus::CANCELLED) {
            return $this->cancelledProfit($r, $nights, $realMoneyCzk);
        }

        [$income, $isEstimate] = $this->resolveIncome($r, $invoices);

        $electricity = $this->electricityCost->cost($r);
        $electricityCzk = $electricity !== null ? Money::normalize($electricity->totalCzk) : '0.00';

        $cleaningCzk = $cleaning !== null ? sprintf('%d.00', $cleaning->getCostCzk()) : '0.00';

        $recreationFeeCzk = sprintf('%d.00', $feePerAdultNight * $r->getGuestsAdult() * $nights);

        $commission = $this->commissionExpense($r);

        $expenses = $commission['expense'];
        foreach ([$electricityCzk, $cleaningCzk, $recreationFeeCzk, $commission['commissionCzk']] as $component) {
            $expenses = bcadd($expenses, $component, 2);
        }

        $profit = $income !== null ? bcsub($income, $expenses, 2) : null;
        $profitPerNight = ($profit !== null && $nights > 0) ? bcdiv($profit, (string) $nights, 2) : null;

        return new ReservationProfit(
            nights: $nights,
            incomeCzk: $income,
            incomeIsEstimate: $isEstimate,
            commissionCzk: $commission['commissionCzk'],
            vatCzk: $commission['vatCzk'],
            vatDeductible: $commission['vatDeductible'],
            electricityCzk: $electricityCzk,
            cleaningCzk: $cleaningCzk,
            recreationFeeCzk: $recreationFeeCzk,
            expensesTotalCzk: $expenses,
            profitCzk: $profit,
            profitPerNightCzk: $profitPerNight,
            cancelled: false,
            missingIncome: $income === null,
            missingElectricity: $electricity === null,
            missingCleaning: $cleaning === null,
        );
    }

    /**
     * Ekonomika zrušené rezervace: na obou stranách jen to, co se opravdu stalo.
     * Příjmem jsou přijaté peníze (nevrácená záloha, storno poplatek), výdaje
     * vázané na pobyt (úklid, elektřina, rekreační poplatek) nevznikly.
     *
     * Provize se počítá jen tehdy, když ji portál skutečně vyúčtoval — tedy když
     * ji potvrzuje reverse charge z jeho faktury. Provize odhadnutá při importu
     * rezervace u zrušeného pobytu nikdy nedorazí.
     */
    private function cancelledProfit(Reservation $r, int $nights, float $realMoneyCzk): ReservationProfit
    {
        $income = Money::normalize(sprintf('%.2f', $realMoneyCzk));
        $commission = $this->commissionExpense($r);
        $invoiced = $r->getVatReverseCharge()->getBaseCzk() !== null;
        $commissionCzk = $invoiced ? $commission['commissionCzk'] : '0.00';
        $vatCzk = $invoiced ? $commission['vatCzk'] : '0.00';
        $expenses = bcadd($commissionCzk, $invoiced ? $commission['expense'] : '0.00', 2);

        return new ReservationProfit(
            nights: $nights,
            incomeCzk: $income,
            incomeIsEstimate: false,
            commissionCzk: $commissionCzk,
            vatCzk: $vatCzk,
            vatDeductible: $commission['vatDeductible'],
            electricityCzk: '0.00',
            cleaningCzk: '0.00',
            recreationFeeCzk: '0.00',
            expensesTotalCzk: $expenses,
            profitCzk: bcsub($income, $expenses, 2),
            // Nocí se nevyužila žádná → zisk na noc nedává smysl.
            profitPerNightCzk: null,
            cancelled: true,
            missingIncome: false,
            missingElectricity: false,
            missingCleaning: false,
        );
    }

    /**
     * Provize portálu a reverse charge z ní. Plátce DPH má na tuhle daň nárok na
     * odpočet → v přiznání se vyruší a výdajem není; provize sama výdajem zůstává.
     *
     * @return array{commissionCzk: string, vatCzk: string, vatDeductible: bool, expense: string}
     */
    private function commissionExpense(Reservation $r): array
    {
        $vatCzk = $r->getVatReverseCharge()->getAmountCzk() ?? '0.00';
        $vatDeductible = $this->taxProfile->current()->chargesOutputVat();

        return [
            'commissionCzk' => $this->resolveCommissionCzk($r),
            'vatCzk' => $vatCzk,
            'vatDeductible' => $vatDeductible,
            'expense' => $vatDeductible ? '0.00' : $vatCzk,
        ];
    }

    /**
     * @param Invoice[] $invoices
     *
     * @return array{0: ?string, 1: bool} [příjem CZK, je odhad]
     */
    private function resolveIncome(Reservation $r, array $invoices): array
    {
        foreach ($invoices as $invoice) {
            if ($invoice->getType() === InvoiceType::FULL) {
                $income = $this->invoiceTotalCzk($r, $invoice);
                if ($income !== null) {
                    return $income;
                }
            }
        }

        foreach ($invoices as $invoice) {
            if ($invoice->getType() === InvoiceType::FINAL) {
                $final = $this->invoiceTotalCzk($r, $invoice);
                $parent = $invoice->getParentInvoice();
                $deposit = $parent !== null ? $this->invoiceTotalCzk($r, $parent) : ['0.00', false];
                if ($final !== null && $deposit !== null) {
                    return [bcadd($final[0], $deposit[0], 2), $final[1] || $deposit[1]];
                }
            }
        }

        // Jen záloha (nebo žádná faktura) → odhad z ceny rezervace.
        $czk = $this->converter->toCzk($r->getPriceTotal(), $r->getPriceCurrency(), $r->getVatReverseCharge()->getCnbRate());
        if ($czk === null) {
            return [null, false];
        }

        return [$czk, $r->getPriceCurrency() !== 'CZK'];
    }

    /**
     * Částka faktury v CZK. Starší faktury z původní fakturace (zahraniční hosté)
     * jsou vystavené v EUR — přepočítáme kurzem faktury, případně uloženým
     * ČNB kurzem rezervace (→ odhad). Bez kurzu fakturu přeskočíme.
     *
     * @return array{0: string, 1: bool}|null [částka CZK, je odhad]
     */
    private function invoiceTotalCzk(Reservation $r, Invoice $invoice): ?array
    {
        $czk = $this->converter->toCzk($invoice->getTotalAmount(), $invoice->getCurrency(), $invoice->getExchangeRate() ?? $r->getVatReverseCharge()->getCnbRate());
        if ($czk === null) {
            return null;
        }

        return [$czk, $invoice->getCurrency() !== 'CZK'];
    }

    private function resolveCommissionCzk(Reservation $r): string
    {
        // vatBaseCzk = provize přepočtená do CZK (základ pro reverse charge) — preferovaný zdroj.
        if ($r->getVatReverseCharge()->getBaseCzk() !== null) {
            return $r->getVatReverseCharge()->getBaseCzk();
        }
        $commission = $r->getCommissionAmount();
        if ($commission === null) {
            return '0.00';
        }

        return $this->converter->toCzk($commission, $r->getCommissionCurrency(), $r->getVatReverseCharge()->getCnbRate()) ?? '0.00';
    }
}
