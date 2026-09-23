<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Formatting;

/**
 * Jméno osoby ve tvaru pro porovnání: malými písmeny a bez diakritiky
 * („Nováková" → „novakova"). Vstup pro hledání hosta podle příjmení
 * i pro párování zákazníků.
 */
final class PersonName
{
    public static function fold(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $ascii = self::transliterator()?->transliterate($lower);

        return \is_string($ascii) ? $ascii : $lower;
    }

    private static function transliterator(): ?\Transliterator
    {
        static $transliterator = null;
        $transliterator ??= \Transliterator::create('Any-Latin; Latin-ASCII');

        return $transliterator;
    }
}
