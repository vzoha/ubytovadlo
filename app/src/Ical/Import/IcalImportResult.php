<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ical\Import;

/** Souhrn jednoho běhu iCal importu feedu. */
final class IcalImportResult
{
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $unchanged,
        public readonly int $cancelled,
        public readonly int $total,
    ) {
    }

    /** Dorazila skrze feed nějaká změna (nová/upravená/stornovaná rezervace)? */
    public function hasActivity(): bool
    {
        return $this->created > 0 || $this->updated > 0 || $this->cancelled > 0;
    }
}
