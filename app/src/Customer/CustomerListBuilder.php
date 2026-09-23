<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

use App\Repository\CustomerRepository;

/**
 * Seznam hostů s ekonomikou: řádky z repozitáře, k nim součty pobytů
 * a řazení podle posledního příjezdu nebo podle příjmu (nejcennější hosté
 * nahoře; při shodě rozhoduje poslední příjezd).
 */
final class CustomerListBuilder
{
    public function __construct(
        private readonly CustomerRepository $customers,
        private readonly CustomerEconomicsCalculator $economics,
    ) {
    }

    /** @return list<CustomerListRow> */
    public function build(?string $search, CustomerListSort $sort): array
    {
        $rows = $this->customers->findForList($search);
        $economics = $this->economics->forCustomers(array_map(static fn (CustomerListRow $row) => $row->customer, $rows));
        $rows = array_map(
            static fn (CustomerListRow $row): CustomerListRow => $row->withEconomics($economics[(int) $row->customer->getId()]),
            $rows,
        );

        if ($sort === CustomerListSort::INCOME) {
            // usort je stabilní — při shodě příjmu zůstane pořadí podle posledního příjezdu.
            usort($rows, static fn (CustomerListRow $a, CustomerListRow $b): int => bccomp($b->economics->incomeCzk, $a->economics->incomeCzk, 2));
        }

        return $rows;
    }
}
