<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ical\Import;

/**
 * Jeden VEVENT z OTA iCal feedu redukovaný na to, co potřebujeme pro obsazenost:
 * stabilní UID, příjezd (DTSTART) a odjezd (DTEND, výlučný — den odjezdu je volný),
 * název a příznak storna. Časy neřešíme — blok je celodenní.
 */
final class IcalEvent
{
    public function __construct(
        public readonly string $uid,
        public readonly \DateTimeImmutable $start,
        public readonly ?\DateTimeImmutable $end,
        public readonly string $summary,
        public readonly bool $cancelled,
    ) {
    }
}
