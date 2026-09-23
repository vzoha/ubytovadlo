<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

/**
 * Pojistka proti sdílenému kontaktu: e-mail ubytovatele zadaný za známého,
 * cestovky nebo společná rodinná adresa se objeví u různých lidí. Dvě jména k sobě sedí, když
 * mají společné slovo — bez diakritiky a s tolerancí k přechýlení (Novák /
 * Nováková, Kučera / Kučerová). Iniciály se nepočítají. Chybějící jméno
 * nerozhoduje, stačí pak shoda kontaktu.
 */
final class NameMatch
{
    private const int MIN_COMMON_PREFIX = 4;

    public static function compatible(?string $a, ?string $b): bool
    {
        $left = self::words($a);
        $right = self::words($b);
        if ($left === [] || $right === []) {
            return true;
        }

        foreach ($left as $x) {
            foreach ($right as $y) {
                if (self::sameWord($x, $y)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Jméno jako porovnatelný klíč: slova bez diakritiky, seřazená — „Nováková
     * Petra" i „petra novakova" dají totéž. Null, když ve jméně nic není.
     */
    public static function key(?string $name): ?string
    {
        $words = array_unique(self::words($name));
        sort($words);

        return $words === [] ? null : implode(' ', $words);
    }

    private static function sameWord(string $x, string $y): bool
    {
        if ($x === $y) {
            return true;
        }

        $common = \strlen(self::commonPrefix($x, $y));
        $shorter = min(\strlen($x), \strlen($y));

        return $common >= max(self::MIN_COMMON_PREFIX, $shorter - 1);
    }

    private static function commonPrefix(string $x, string $y): string
    {
        $length = min(\strlen($x), \strlen($y));
        $i = 0;
        while ($i < $length && $x[$i] === $y[$i]) {
            $i++;
        }

        return substr($x, 0, $i);
    }

    /** @return string[] */
    private static function words(?string $name): array
    {
        $lower = mb_strtolower(trim((string) $name));
        $ascii = self::transliterator()?->transliterate($lower);
        $plain = \is_string($ascii) ? $ascii : $lower;

        $words = preg_split('/[^a-z0-9]+/', $plain, -1, \PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, static fn (string $word): bool => \strlen($word) >= 2));
    }

    private static function transliterator(): ?\Transliterator
    {
        static $transliterator = null;
        $transliterator ??= \Transliterator::create('Any-Latin; Latin-ASCII');

        return $transliterator;
    }
}
