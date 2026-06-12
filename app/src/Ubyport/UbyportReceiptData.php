<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ubyport;

/**
 * Vyparsovaná data z doručenky Ubyportu (PDF "doručenka elektronického
 * oznámení ubytování cizinců ubytovatelem"). Slouží ke kontrole, že nahlášení
 * proběhlo a počet přijatých záznamů sedí na počet hlášených cizinců.
 */
final readonly class UbyportReceiptData
{
    public function __construct(
        public ?string $idub,
        public int $total,
        public int $accepted,
        public int $rejected,
        public int $ignored,
    ) {
    }

    /** Vše v pořádku: aspoň jeden záznam, nic nepřijatého ani ignorovaného. */
    public function isAllAccepted(): bool
    {
        return $this->total > 0
            && $this->rejected === 0
            && $this->ignored === 0
            && $this->accepted === $this->total;
    }
}
