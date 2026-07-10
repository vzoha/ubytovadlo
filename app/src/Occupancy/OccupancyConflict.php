<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Occupancy;

use App\Entity\Reservation;

/**
 * Dvě aktivní rezervace, jejichž pobyty se překrývají — riziko dvojího prodeje
 * stejného termínu (typicky když sync z více kanálů založí kolidující bloky).
 * `from`/`to` je průnik obou pobytů (půlotevřený interval [from; to)).
 */
final readonly class OccupancyConflict
{
    public function __construct(
        public Reservation $a,
        public Reservation $b,
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
    }

    /** Počet překrývajících se nocí. */
    public function nights(): int
    {
        return (int) $this->from->diff($this->to)->days;
    }
}
