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
 * Bodová událost na konkrétní den — úklid po pobytu, hlídaný termín.
 * `tone` je bootstrapová barva odznaku.
 */
final readonly class CalendarMarker
{
    public function __construct(
        public int $day,
        public string $icon,
        public string $label,
        public string $tone,
        public ?string $url = null,
    ) {
    }
}
