<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

use App\Entity\Customer;

/**
 * Dvojice zákazníků, kteří jsou možná jeden host. `keep` je starší záznam —
 * při sloučení zůstane on a `merge` do něj přejde.
 */
final readonly class DuplicateSuggestion
{
    public function __construct(
        public Customer $keep,
        public Customer $merge,
        public DuplicateReason $reason,
    ) {
    }
}
