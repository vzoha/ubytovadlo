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
 * Formát zobrazovaného čísla faktury. Vzor s proměnnými (konvence jako iDoklad):
 *  - `{RRRR}` / `{RR}` — rok (4 nebo 2 cifry)
 *  - `{N}`..`{NNNNN}` — pořadové číslo, počet písmen = počet cifer (padding nulami)
 *  - libovolný pevný text (předpona, oddělovače): `FA-{RRRR}-{NNN}` → `FA-2026-012`
 *
 * Pořadové číslo se drží ve vlastním sloupci (Invoice::seriesSequence), takže na
 * formátu nezáleží při alokaci ani při čtení nejvyššího čísla. Variabilní symbol
 * zůstává číselný nezávisle na formátu (viz InvoiceNumber::formatted()).
 *
 * Přednost má hodnota z DB (setting `invoice.number_format`), fallback je
 * DEFAULT — instance si formát nastaví v UI (/nastaveni/dodavatel).
 */
final class InvoiceNumberFormat
{
    public const KEY = 'invoice.number_format';
    public const DEFAULT = '{RRRR}{NNN}';

    /** Povolené pevné znaky mimo tokeny (předpona, oddělovače). */
    private const LITERAL_PATTERN = '/^[A-Za-z0-9\-_\/. ]*$/';

    private const TOKEN_PATTERN = '/\{(RRRR|RR|N{1,5})\}/';

    public function __construct(
        private readonly SettingRepository $settings,
        private readonly string $fallback = self::DEFAULT,
    ) {
    }

    public function pattern(): string
    {
        $stored = $this->settings->getString(self::KEY);
        if ($stored !== null && $stored !== '' && self::isValid($stored)) {
            return $stored;
        }

        return self::isValid($this->fallback) ? $this->fallback : self::DEFAULT;
    }

    public function format(int $year, int $sequence): string
    {
        return self::render($this->pattern(), $year, $sequence);
    }

    public static function render(string $pattern, int $year, int $sequence): string
    {
        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            static function (array $m) use ($year, $sequence): string {
                return match ($m[1]) {
                    'RRRR' => sprintf('%04d', $year),
                    'RR' => sprintf('%02d', $year % 100),
                    default => str_pad((string) $sequence, strlen($m[1]), '0', STR_PAD_LEFT),
                };
            },
            $pattern,
        );
    }

    /**
     * Platný vzor musí obsahovat token roku i pořadí (jinak čísla kolidují napříč
     * roky) a pevná část smí být jen z bezpečné znakové sady.
     */
    public static function isValid(string $pattern): bool
    {
        if (preg_match('/\{(RRRR|RR)\}/', $pattern) !== 1) {
            return false;
        }
        if (preg_match('/\{N{1,5}\}/', $pattern) !== 1) {
            return false;
        }

        $literals = (string) preg_replace(self::TOKEN_PATTERN, '', $pattern);

        return preg_match(self::LITERAL_PATTERN, $literals) === 1;
    }
}
