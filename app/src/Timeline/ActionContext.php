<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Timeline;

use App\Entity\ReservationNote;
use App\Enum\MessageOutlook;

/** Doplňky akce na časové ose, které nevyčte z akce samotné. */
final readonly class ActionContext
{
    /**
     * @param MessageOutlook|null   $outlook   jak zpráva dopadne; null u akce, která zprávu neposílá nebo už je uzavřená
     * @param list<ReservationNote> $followUps zápisy navázané na akci (stejná zpráva ještě jinou cestou)
     */
    public function __construct(
        public ?MessageOutlook $outlook = null,
        public array $followUps = [],
    ) {
    }
}
