<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

final readonly class InvoiceNumber
{
    public function __construct(
        public int $year,
        public int $sequence,
    ) {
        if ($sequence < 1 || $sequence > 999) {
            throw new \InvalidArgumentException(sprintf('Sequence %d out of range 1..999', $sequence));
        }
    }

    public function formatted(): string
    {
        return sprintf('%04d%03d', $this->year, $this->sequence);
    }

    public function __toString(): string
    {
        return $this->formatted();
    }
}
