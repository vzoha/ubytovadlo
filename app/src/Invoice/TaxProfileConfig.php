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
use App\Repository\SettingRepository;

/**
 * Daňový profil dodavatele z DB (setting `invoice.issuer.tax_profile`). Instance
 * si profil nastaví v UI (/nastaveni/dodavatel); bez nastavení jede jako
 * identifikovaná osoba (§6h ZDPH) — výchozí chování.
 */
final class TaxProfileConfig
{
    public const KEY = 'invoice.issuer.tax_profile';

    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    public function current(): TaxProfile
    {
        $stored = $this->settings->getString(self::KEY);

        return $stored === null ? TaxProfile::IDENTIFIED_PERSON : (TaxProfile::tryFrom($stored) ?? TaxProfile::IDENTIFIED_PERSON);
    }
}
