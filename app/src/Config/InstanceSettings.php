<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Config;

use App\Repository\SettingRepository;

/**
 * Per-instance identita a základní adresa aplikace. Každá instance si je nastaví
 * v UI (/nastaveni/obecne). Bez nastavení má značka produktový default, adresa je prázdná.
 */
final class InstanceSettings
{
    public const KEY_BRAND_NAME = 'app.brand_name';
    public const KEY_BASE_URL = 'app.base_url';

    /** Poslední záchrana, když není nastavená značka. */
    private const DEFAULT_BRAND_NAME = 'Ubytovadlo';

    public function __construct(
        private readonly SettingRepository $settings,
    ) {
    }

    public function brandName(): string
    {
        return $this->value(self::KEY_BRAND_NAME) ?: self::DEFAULT_BRAND_NAME;
    }

    public function baseUrl(): string
    {
        return $this->value(self::KEY_BASE_URL);
    }

    /**
     * Aktuální hodnoty z DB pro předvyplnění formuláře.
     *
     * @return array{brandName: string, baseUrl: string}
     */
    public function currentValues(): array
    {
        return [
            'brandName' => $this->brandName(),
            'baseUrl' => $this->baseUrl(),
        ];
    }

    private function value(string $key): string
    {
        return (string) $this->settings->getString($key);
    }
}
