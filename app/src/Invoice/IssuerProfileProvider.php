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
 * v UI (/nastaveni/dodavatel) místo editace .env. Hodnoty z .env (INVOICE_ISSUER_*)
 * slouží už jen jako fallback pro nenakonfigurované instance / vývoj.
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
        // Fallback z .env (INVOICE_ISSUER_* / INVOICE_BANK_*).
        private readonly string $name,
        private readonly string $street,
        private readonly string $city,
        private readonly string $zip,
        private readonly string $country,
        private readonly string $ico,
        private readonly string $dic,
        private readonly string $phone,
        private readonly string $email,
        private readonly string $web,
        private readonly string $bankAccount,
        private readonly string $bankAccountIban,
    ) {
    }

    public function current(): IssuerProfile
    {
        return new IssuerProfile(
            name: $this->value('name', $this->name),
            street: $this->value('street', $this->street),
            city: $this->value('city', $this->city),
            zip: $this->value('zip', $this->zip),
            country: $this->value('country', $this->country),
            ico: $this->value('ico', $this->ico),
            dic: $this->value('dic', $this->dic),
            phone: $this->value('phone', $this->phone),
            email: $this->value('email', $this->email),
            web: $this->value('web', $this->web),
            bankAccount: $this->value('bankAccount', $this->bankAccount),
            bankAccountIban: $this->value('bankAccountIban', $this->bankAccountIban),
        );
    }

    /**
     * Aktuální hodnoty (DB s fallbackem na env) pro předvyplnění formuláře.
     *
     * @return array<string, string>
     */
    public function currentValues(): array
    {
        $values = [];
        foreach (array_keys(self::KEYS) as $field) {
            $values[$field] = $this->value($field, $this->{$field});
        }

        return $values;
    }

    private function value(string $field, string $fallback): string
    {
        $stored = $this->settings->getString(self::KEYS[$field]);

        return $stored !== null && $stored !== '' ? $stored : $fallback;
    }
}
