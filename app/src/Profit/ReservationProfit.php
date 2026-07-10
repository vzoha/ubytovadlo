<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Profit;

/**
 * Ekonomika jedné rezervace — zrcadlí výpočet z původní ruční evidence:
 * Výdaje = elektřina + úklid + rekreační poplatek + OTA provize + DPH (reverse charge),
 * Zisk = Příjem − Výdaje. Všechny částky jsou bcmath stringy v CZK, scale 2.
 *
 * U plátce DPH má reverse charge z provize nárok na odpočet (`vatDeductible`), takže
 * se do výdajů nezapočítává — `vatCzk` zůstává jen informativní částkou.
 */
final readonly class ReservationProfit
{
    public function __construct(
        public int $nights,
        public ?string $incomeCzk,
        public bool $incomeIsEstimate,
        public string $commissionCzk,
        public string $vatCzk,
        public bool $vatDeductible,
        public string $electricityCzk,
        public string $cleaningCzk,
        public string $recreationFeeCzk,
        public string $expensesTotalCzk,
        public ?string $profitCzk,
        public ?string $profitPerNightCzk,
        public bool $missingIncome,
        public bool $missingElectricity,
        public bool $missingCleaning,
    ) {
    }
}
