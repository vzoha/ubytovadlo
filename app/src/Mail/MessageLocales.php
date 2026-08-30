<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Mail;

/**
 * Jazyky, ve kterých chodí zprávy hostům. Základní jazyk drží režim odesílání
 * i časování na ose rezervace a slouží jako záloha, když překlad chybí —
 * ostatní jazyky nesou jen text (předmět + tělo).
 */
final class MessageLocales
{
    public const BASE = 'cs';

    /** @var array<string, string> kód => název jazyka */
    public const ALL = [
        'cs' => 'Čeština',
        'en' => 'Angličtina',
    ];

    public static function isSupported(string $locale): bool
    {
        return isset(self::ALL[$locale]);
    }

    /** Podporovaný kód jazyka, jinak základní jazyk. */
    public static function normalize(?string $locale): string
    {
        return $locale !== null && self::isSupported($locale) ? $locale : self::BASE;
    }

    public static function label(string $locale): string
    {
        return self::ALL[$locale] ?? $locale;
    }

    /** @return list<string> */
    public static function translations(): array
    {
        return array_values(array_filter(array_keys(self::ALL), static fn (string $l): bool => $l !== self::BASE));
    }
}
