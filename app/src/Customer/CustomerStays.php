<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

use App\Entity\Reservation;

/**
 * Kolikátý pobyt zákazníka je tahle rezervace a jaké má další. Zrušené
 * pobyty se nepočítají; zrušená rezervace sama pořadí nemá.
 */
final readonly class CustomerStays
{
    /**
     * @param Reservation[] $stays pobyty zákazníka v pořadí příjezdů
     */
    public function __construct(
        private Reservation $current,
        private array $stays,
    ) {
    }

    /** Pořadí pobytu od 1; null u zrušené rezervace. */
    public function ordinal(): ?int
    {
        foreach ($this->stays as $index => $stay) {
            if ($stay === $this->current) {
                return $index + 1;
            }
        }

        return null;
    }

    /** Host tu byl už dřív nebo přijede znovu. */
    public function isReturning(): bool
    {
        return $this->others() !== [];
    }

    /**
     * Ostatní pobyty zákazníka, od nejnovějšího.
     *
     * @return Reservation[]
     */
    public function others(): array
    {
        $others = array_filter($this->stays, fn (Reservation $stay): bool => $stay !== $this->current);

        return array_reverse(array_values($others));
    }
}
