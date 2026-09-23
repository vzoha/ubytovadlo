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

/** Řádek seznamu hostů. */
final readonly class CustomerListRow
{
    public function __construct(
        public Customer $customer,
        public int $stays,
        public \DateTimeImmutable $lastCheckIn,
        public CustomerEconomics $economics,
    ) {
    }

    public function withEconomics(CustomerEconomics $economics): self
    {
        return new self($this->customer, $this->stays, $this->lastCheckIn, $economics);
    }
}
