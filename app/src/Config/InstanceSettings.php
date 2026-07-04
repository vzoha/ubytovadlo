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
 * v UI (/nastaveni/obecne) místo editace .env. Přednost má hodnota z DB (setting),
 * fallback jsou hodnoty z .env (APP_BRAND_NAME / DEFAULT_URI) — díky tomu čerstvá
 * instance běží z env a nakonfigurovaná si je přepíše v UI.
 */
final class InstanceSettings
{
    public const KEY_BRAND_NAME = 'app.brand_name';
    public const KEY_BASE_URL = 'app.base_url';

    /** Poslední záchrana, když není ani setting ani env fallback. */
    private const DEFAULT_BRAND_NAME = 'Ubytovadlo';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly string $brandNameFallback,
        private readonly string $baseUrlFallback,
    ) {
    }

    public function brandName(): string
    {
        return $this->value(self::KEY_BRAND_NAME, $this->brandNameFallback) ?: self::DEFAULT_BRAND_NAME;
    }

    public function baseUrl(): string
    {
        return $this->value(self::KEY_BASE_URL, $this->baseUrlFallback);
    }

    /**
     * Aktuální hodnoty (DB s fallbackem na env) pro předvyplnění formuláře.
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

    private function value(string $key, string $fallback): string
    {
        $stored = $this->settings->getString($key);

        return $stored !== null && $stored !== '' ? $stored : $fallback;
    }
}
