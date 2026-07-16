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
 * Režim odesílání zprávy hostovi:
 *  - AUTO  — v naplánovaný čas se e-mail odešle sám,
 *  - DRAFT — na časové ose rezervace čeká připravená k ručnímu odeslání (nikdy sama),
 *  - OFF   — zpráva se na osu vůbec nezaloží.
 */
enum SendMode: string
{
    case AUTO = 'auto';
    case DRAFT = 'draft';
    case OFF = 'off';

    public function label(): string
    {
        return match ($this) {
            self::AUTO => 'Automaticky',
            self::DRAFT => 'Ručně',
            self::OFF => 'Vypnuto',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::AUTO => 'V naplánovaný čas se e-mail odešle sám.',
            self::DRAFT => 'Zpráva čeká na časové ose rezervace, odešleš ji tlačítkem.',
            self::OFF => 'Zpráva se nezakládá ani neplánuje.',
        };
    }

    /** Časování (kdy odeslat) má smysl nastavovat jen u zpráv, které se plánují. */
    public function usesTiming(): bool
    {
        return $this !== self::OFF;
    }

    public function badge(): string
    {
        return match ($this) {
            self::AUTO => 'success',
            self::DRAFT => 'info',
            self::OFF => 'secondary',
        };
    }
}
