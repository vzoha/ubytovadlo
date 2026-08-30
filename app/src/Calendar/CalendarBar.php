<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Calendar;

use App\Enum\Channel;

/**
 * Rezervace připravená k vykreslení — obsah pásu (kdo, odkud, v jakém stavu)
 * oddělený od jeho geometrie ({@see BarSpan}).
 */
final readonly class CalendarBar
{
    public function __construct(
        public int $reservationId,
        public string $label,
        public string $summary,
        public Channel $channel,
        public bool $unconfirmed,
        public bool $conflict,
        public BarSpan $span,
    ) {
    }

    /** Týž pás oříznutý na okno (týden v mřížce); null, když do okna nesahá. */
    public function clipTo(float $windowStart, float $windowDays): ?self
    {
        $span = $this->span->clipTo($windowStart, $windowDays);
        if ($span === null) {
            return null;
        }

        return new self(
            $this->reservationId,
            $this->label,
            $this->summary,
            $this->channel,
            $this->unconfirmed,
            $this->conflict,
            $span,
        );
    }
}
