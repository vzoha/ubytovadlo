<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

use App\Entity\Customer;
use App\Entity\Reservation;
use App\Profit\ReservationProfit;
use App\Profit\ReservationProfitCalculator;
use App\Repository\ReservationRepository;

/**
 * Ekonomika hostů z ekonomiky jejich pobytů. Počítá dávkou — seznam hostů
 * tak nedělá dotaz za každého hosta ani za každý pobyt.
 */
final class CustomerEconomicsCalculator
{
    public function __construct(
        private readonly ReservationRepository $reservations,
        private readonly ReservationProfitCalculator $profits,
    ) {
    }

    /**
     * @param Customer[] $customers
     *
     * @return array<int, CustomerEconomics> klíč = id zákazníka; host bez pobytů má prázdnou ekonomiku
     */
    public function forCustomers(array $customers): array
    {
        $reservations = $this->reservations->findAllOfCustomers($customers);
        $profits = $this->profits->calculateBatch($reservations);

        $stays = [];
        foreach ($reservations as $reservation) {
            $stays[(int) $reservation->getCustomer()?->getId()][] = [$reservation, $profits[(int) $reservation->getId()]];
        }

        $result = [];
        foreach ($customers as $customer) {
            $id = (int) $customer->getId();
            $result[$id] = CustomerEconomics::fromStays($stays[$id] ?? []);
        }

        return $result;
    }

    /**
     * Ekonomika jednoho hosta z jeho pobytů a ekonomika každého z nich.
     *
     * @param Reservation[] $stays pobyty jednoho zákazníka
     *
     * @return array{CustomerEconomics, array<int, ReservationProfit>} součet + ekonomika pobytů podle id rezervace
     */
    public function forStays(array $stays): array
    {
        $profits = $this->profits->calculateBatch($stays);
        $pairs = array_map(static fn (Reservation $r): array => [$r, $profits[(int) $r->getId()]], $stays);

        return [CustomerEconomics::fromStays(array_values($pairs)), $profits];
    }
}
