<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Enum\TaxProfile;

/**
 * Snímek dodavatele pro účely faktury. Hodnoty jdou z DB (settings `invoice.issuer.*`).
 */
final readonly class IssuerProfile
{
    public function __construct(
        public string $name,
        public string $street,
        public string $city,
        public string $zip,
        public string $country,
        public string $ico,
        public string $dic,
        public string $phone,
        public string $email,
        public string $web,
        public string $bankAccount,
        public string $bankAccountIban,
        public TaxProfile $taxProfile = TaxProfile::IDENTIFIED_PERSON,
    ) {
    }
}
