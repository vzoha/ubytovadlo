<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Calendar;

use App\Entity\Reservation;

/**
 * Zdroj jednotek pro kalendář a zařazení rezervace pod jednotku. Jediné místo,
 * které ví, kolik jednotek se pronajímá — builder i šablony pracují s polem
 * řádků bez ohledu na jejich počet.
 */
interface UnitProvider
{
    /** @return list<CalendarUnit> v pořadí, v jakém se mají kreslit řádky */
    public function units(): array;

    /** ID jednotky, do jejíhož řádku rezervace patří. */
    public function unitIdFor(Reservation $reservation): string;
}
