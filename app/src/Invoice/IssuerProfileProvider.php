<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Repository\SettingRepository;

/**
 * Sestaví dodavatele faktury (IssuerProfile) z DB (tabulka setting, klíče
 * `invoice.issuer.*` a `invoice.bank.*`). Každá instance si dodavatele nastaví
 * v UI (/nastaveni/dodavatel); nenakonfigurovaná instance nemá dodavatele (prázdno).
 */
final class IssuerProfileProvider
{
    /** Setting klíče dodavatele. */
    public const KEYS = [
        'name' => 'invoice.issuer.name',
        'street' => 'invoice.issuer.street',
        'city' => 'invoice.issuer.city',
        'zip' => 'invoice.issuer.zip',
        'country' => 'invoice.issuer.country',
        'ico' => 'invoice.issuer.ico',
        'dic' => 'invoice.issuer.dic',
        'phone' => 'invoice.issuer.phone',
        'email' => 'invoice.issuer.email',
        'web' => 'invoice.issuer.web',
        'bankAccount' => 'invoice.bank.account',
        'bankAccountIban' => 'invoice.bank.iban',
    ];

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly TaxProfileConfig $taxProfile,
    ) {
    }

    public function current(): IssuerProfile
    {
        return new IssuerProfile(
            name: $this->value('name'),
            street: $this->value('street'),
            city: $this->value('city'),
            zip: $this->value('zip'),
            country: $this->value('country'),
            ico: $this->value('ico'),
            dic: $this->value('dic'),
            phone: $this->value('phone'),
            email: $this->value('email'),
            web: $this->value('web'),
            bankAccount: $this->value('bankAccount'),
            bankAccountIban: $this->value('bankAccountIban'),
            taxProfile: $this->taxProfile->current(),
        );
    }

    /**
     * Aktuální hodnoty z DB pro předvyplnění formuláře.
     *
     * @return array<string, string>
     */
    public function currentValues(): array
    {
        $values = [];
        foreach (array_keys(self::KEYS) as $field) {
            $values[$field] = $this->value($field);
        }

        return $values;
    }

    private function value(string $field): string
    {
        return (string) $this->settings->getString(self::KEYS[$field]);
    }
}
