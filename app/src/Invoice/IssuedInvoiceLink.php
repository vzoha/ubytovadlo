<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Entity\InvoiceLink;

/** Právě vytvořený odkaz — URL s tokenem existuje jen v tuhle chvíli, v DB je otisk. */
final class IssuedInvoiceLink
{
    public function __construct(
        public readonly InvoiceLink $link,
        public readonly string $url,
    ) {
    }
}
