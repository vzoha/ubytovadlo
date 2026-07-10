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
 * Rekapitulace výstupní DPH faktury po sazbách. Cena řádků je brutto (včetně DPH),
 * daň se počítá **shora**: základ = brutto / (1 + sazba/100), DPH = brutto − základ.
 * Řádky bez sazby (null) se do rekapitulace nezahrnují — faktura bez DPH má prázdnou
 * rekapitulaci. Záporné řádky (odpočet zálohy) se v rámci sazby normálně odečtou,
 * takže konečná faktura vykáže jen doplatek.
 *
 * Sdílí PDF faktury i budoucí DPH přehled — jeden zdroj pravdy pro rozpad daně.
 */
final readonly class InvoiceVatRecap
{
    /** @param list<InvoiceVatRecapRow> $rows */
    private function __construct(
        public array $rows,
        public string $baseTotal,
        public string $vatTotal,
        public string $grossTotal,
    ) {
    }

    public static function fromInvoice(Invoice $invoice): self
    {
        /** @var array<string, string> $grossByRate součet brutto pro každou sazbu */
        $grossByRate = [];
        foreach ($invoice->getLines() as $line) {
            $rate = $line->getVatRate();
            if ($rate === null) {
                continue;
            }
            $grossByRate[$rate] = bcadd($grossByRate[$rate] ?? '0', $line->getTotalPrice(), 2);
        }

        ksort($grossByRate, SORT_NUMERIC);

        $rows = [];
        $baseTotal = '0';
        $vatTotal = '0';
        $grossTotal = '0';
        foreach ($grossByRate as $rate => $gross) {
            $base = self::baseFromGross($gross, $rate);
            $vat = bcsub($gross, $base, 2);
            $rows[] = new InvoiceVatRecapRow($rate, $base, $vat, $gross);
            $baseTotal = bcadd($baseTotal, $base, 2);
            $vatTotal = bcadd($vatTotal, $vat, 2);
            $grossTotal = bcadd($grossTotal, $gross, 2);
        }

        return new self($rows, $baseTotal, $vatTotal, $grossTotal);
    }

    public function hasVat(): bool
    {
        return $this->rows !== [];
    }

    /** Základ daně z brutto částky „shora" pro danou sazbu (v procentech). */
    private static function baseFromGross(string $gross, string $rate): string
    {
        $divisor = bcadd('1', bcdiv($rate, '100', 6), 6);
        $base = (float) $gross / (float) $divisor;

        return number_format(round($base, 2), 2, '.', '');
    }
}
