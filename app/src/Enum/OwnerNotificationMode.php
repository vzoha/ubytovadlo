<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Enum;

/**
 * Způsob doručení notifikace ubytovateli:
 *  - IMMEDIATE: samostatný e-mail při nejbližším běhu dispatch cronu,
 *  - DIGEST:    posbírá se do jednoho denního souhrnu,
 *  - OFF:       daný typ se vůbec neeviduje.
 */
enum OwnerNotificationMode: string
{
    case IMMEDIATE = 'immediate';
    case DIGEST = 'digest';
    case OFF = 'off';

    public function label(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'Okamžitě',
            self::DIGEST => 'Denní souhrn',
            self::OFF => 'Vypnuto',
        };
    }

    public static function fromValue(?string $value, self $default): self
    {
        return $value !== null ? (self::tryFrom($value) ?? $default) : $default;
    }
}
