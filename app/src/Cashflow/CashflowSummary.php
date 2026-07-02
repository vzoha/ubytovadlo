<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Cashflow;

use App\Enum\LedgerEntryType;
use App\Repository\LedgerEntryRepository;
use App\Repository\ReservationReceiptRepository;

/**
 * Měsíční souhrn cashflow za rok: reálně přijaté platby z rezervací proti
 * výdajům (dělené na provozní vs. neprovozní/osobní odliv). Každá dílčí platba
 * (záloha, doplatek, výplata) se počítá v měsíci svého přijetí. Převody mezi
 * vlastními účty ani korekce z uzávěrek se nezapočítávají — nejsou to reálné
 * příjmy/náklady, jen interní přesun peněz mezi účty.
 */
final class CashflowSummary
{
    public function __construct(
        private readonly ReservationReceiptRepository $receipts,
        private readonly LedgerEntryRepository $ledger,
    ) {
    }

    /**
     * @return array{months: array<int, array{month: int, income: int, operating: int, nonOperating: int, net: int}>, totals: array{income: int, operating: int, nonOperating: int, net: int}}
     */
    public function forYear(int $year): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
        $to = new \DateTimeImmutable(sprintf('%04d-12-31', $year));

        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[$m] = ['month' => $m, 'income' => 0, 'operating' => 0, 'nonOperating' => 0, 'net' => 0];
        }

        foreach ($this->receipts->findReceivedBetween($from, $to) as $receipt) {
            $received = $receipt->getReceivedOn();
            if ($received === null) {
                continue;
            }
            $months[(int) $received->format('n')]['income'] += (int) round((float) $receipt->getAmountCzk());
        }

        // Nerezervační příjmy (úroky, storno-poplatky) — do příjmu měsíce, kdy nastaly.
        foreach ($this->ledger->findIncomeInYear($year) as $income) {
            $months[(int) $income->getOccurredOn()->format('n')]['income'] += $income->getAmountCzk();
        }

        foreach ($this->ledger->findExpensesInYear($year) as $expense) {
            if ($expense->getType() !== LedgerEntryType::EXPENSE) {
                continue;
            }
            $bucket = $expense->getCategory()?->isOperating() ?? true ? 'operating' : 'nonOperating';
            $months[(int) $expense->getOccurredOn()->format('n')][$bucket] += $expense->getAmountCzk();
        }

        $totals = ['income' => 0, 'operating' => 0, 'nonOperating' => 0, 'net' => 0];
        foreach ($months as $m => $row) {
            $net = $row['income'] - $row['operating'] - $row['nonOperating'];
            $months[$m]['net'] = $net;
            $totals['income'] += $row['income'];
            $totals['operating'] += $row['operating'];
            $totals['nonOperating'] += $row['nonOperating'];
            $totals['net'] += $net;
        }

        return ['months' => array_values($months), 'totals' => $totals];
    }
}
