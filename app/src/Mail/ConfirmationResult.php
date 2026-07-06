<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

/**
 * Výsledek potvrzení rezervace: zda odešel potvrzovací e-mail, zda se změnil stav
 * na „Potvrzeno" a případný důvod, proč se e-mail neposlal.
 */
final readonly class ConfirmationResult
{
    public function __construct(
        public bool $emailSent,
        public bool $statusChanged,
        public ?string $skipReason,
    ) {
    }
}
