<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Checkin;

/**
 * Průběh online check-inu pro časovou osu: kdy host začal, jednotlivé kroky
 * a jednořádkový souhrn.
 */
final readonly class CheckinProgress
{
    /**
     * @param list<string> $steps řádky „čas krok"
     */
    public function __construct(
        public \DateTimeImmutable $startedAt,
        public array $steps,
        public string $summary,
    ) {
    }
}
