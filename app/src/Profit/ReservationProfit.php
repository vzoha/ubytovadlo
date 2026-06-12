<?php

declare(strict_types=1);

namespace App\Profit;

/**
 * Ekonomika jedné rezervace — zrcadlí výpočet z původní ruční evidence:
 * Výdaje = elektřina + úklid + rekreační poplatek + OTA provize + DPH (reverse charge),
 * Zisk = Příjem − Výdaje. Všechny částky jsou bcmath stringy v CZK, scale 2.
 */
final readonly class ReservationProfit
{
    public function __construct(
        public int $nights,
        public ?string $incomeCzk,
        public bool $incomeIsEstimate,
        public string $commissionCzk,
        public string $vatCzk,
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
