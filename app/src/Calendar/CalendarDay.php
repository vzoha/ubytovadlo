<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Calendar;

/**
 * Jeden sloupec kalendáře. Zkratku dne a příznaky si nese s sebou, ať šablona
 * neřeší formátování ani češtinu.
 */
final readonly class CalendarDay
{
    public function __construct(
        public \DateTimeImmutable $date,
        public int $index,
        public string $shortName,
        public bool $today,
        public bool $weekend,
        public bool $outsideMonth,
    ) {
    }

    public function number(): int
    {
        return (int) $this->date->format('j');
    }
}
