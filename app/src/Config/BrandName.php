<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Config;

/**
 * Twig global `brand_name` jako Stringable — díky tomu `{{ brand_name }}` čte
 * název instance z nastavení (s env fallbackem) až při renderu, ne z env při
 * buildu kontejneru.
 */
final class BrandName implements \Stringable
{
    public function __construct(private readonly InstanceSettings $settings)
    {
    }

    public function __toString(): string
    {
        return $this->settings->brandName();
    }
}
