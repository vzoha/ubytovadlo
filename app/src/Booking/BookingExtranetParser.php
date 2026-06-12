<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Booking;

use App\Reservation\GuestRequestKeywords;

/**
 * Parser textu zkopirovaneho z Booking extranetu (detail rezervace).
 *
 * Cesky extranet ma format "label / prazdna radka / hodnota". Parsujeme
 * po radkach, pripadne globalne (e-mail, telefon).
 */
class BookingExtranetParser
{
    private const CZECH_GENITIVE_MONTHS = [
        'ledna' => 1,
        'února' => 2,
        'března' => 3,
        'dubna' => 4,
        'května' => 5,
        'června' => 6,
        'července' => 7,
        'srpna' => 8,
        'září' => 9,
        'října' => 10,
        'listopadu' => 11,
        'prosince' => 12,
    ];

    public function parse(string $raw): BookingExtranetData
    {
        $data = new BookingExtranetData();
        $lines = $this->normalizeLines($raw);

        foreach ($lines as $i => $line) {
            $next = $lines[$i + 1] ?? null;
            if ($next === null) {
                continue;
            }

            $label = rtrim($line, ':');
            switch ($label) {
                case 'Datum příjezdu':
                    $data->checkIn = $this->parseCzechDate($next);
                    break;
                case 'Datum odjezdu':
                    $data->checkOut = $this->parseCzechDate($next);
                    break;
                case 'Celkový počet hostů':
                    [$adults, $children] = $this->parseGuests($next);
                    $data->guestsAdult = $adults;
                    if ($children > 0) {
                        $data->guestsChild = $children;
                    }
                    break;
                case 'Celková cena':
                    [$amount, $currency] = $this->parseAmount($next);
                    if ($amount !== null) {
                        $data->priceTotal = $amount;
                        $data->priceCurrency = $currency;
                    }
                    break;
                case 'Číslo rezervace':
                    $data->externalId = trim($next);
                    break;
                case 'Provize a poplatky':
                    [$amount, $currency] = $this->parseAmount($next);
                    if ($amount !== null) {
                        $data->commissionAmount = $amount;
                        $data->commissionCurrency = $currency;
                    }
                    break;
                case 'Důležitá informace o tomto hostovi':
                    $note = trim($next);
                    if ($note !== '') {
                        $data->notes = $note;
                    }
                    break;
            }
        }

        $this->parseCustomerBlock($lines, $data);
        $this->detectPet($lines, $data);
        $this->detectBabyCot($lines, $data);

        return $data;
    }

    /**
     * @param list<string> $lines
     */
    private function detectBabyCot(array $lines, BookingExtranetData $data): void
    {
        foreach ($lines as $line) {
            if (GuestRequestKeywords::mentionsBabyCot($line)) {
                $data->needsBabyCot = true;

                return;
            }
        }
    }

    /**
     * @param list<string> $lines
     */
    private function detectPet(array $lines, BookingExtranetData $data): void
    {
        foreach ($lines as $line) {
            // "+ 1 pes (tornjak)" / "1 pes" / "pes" / "psi" / "se psem"
            if (!GuestRequestKeywords::mentionsPet($line)) {
                continue;
            }
            $data->hasPet = true;
            // zkusime vytahnout plemeno ze zavorky, jinak ulozime celou radku jako poznamku
            if (preg_match('/\(([^)]+)\)/u', $line, $m) === 1) {
                $data->petsNote = trim($m[1]);
            } else {
                $note = trim(ltrim($line, '+ -*'));
                if ($note !== '') {
                    $data->petsNote = $note;
                }
            }

            return;
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeLines(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);
        $lines = [];
        foreach (explode("\n", $raw) as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $lines[] = $trimmed;
            }
        }

        return $lines;
    }

    private function parseCzechDate(string $value): ?\DateTimeImmutable
    {
        // "ne, 10. května 2026"
        if (preg_match('/(\d{1,2})\.\s*([\p{L}]+)\s+(\d{4})/u', $value, $m) !== 1) {
            return null;
        }
        $month = self::CZECH_GENITIVE_MONTHS[mb_strtolower($m[2])] ?? null;
        if ($month === null) {
            return null;
        }
        try {
            return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', (int) $m[3], $month, (int) $m[1]));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array{0: int|null, 1: int}
     */
    private function parseGuests(string $value): array
    {
        $adults = null;
        $children = 0;
        if (preg_match('/(\d+)\s*dospěl/u', $value, $m) === 1) {
            $adults = (int) $m[1];
        }
        if (preg_match('/(\d+)\s*(?:dít|dět)/u', $value, $m) === 1) {
            $children = (int) $m[1];
        }

        return [$adults, $children];
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseAmount(string $value): array
    {
        // "€ 364,32" / "364,32 €" / "Kč 14 000,00" / "14 000 CZK"
        if (preg_match('/(€|EUR)/u', $value) === 1) {
            $currency = 'EUR';
        } elseif (preg_match('/(Kč|CZK)/u', $value) === 1) {
            $currency = 'CZK';
        } else {
            $currency = null;
        }

        $stripped = preg_replace('/€|EUR|Kč|CZK/u', '', $value) ?? '';
        $stripped = trim((string) preg_replace('/\s+/', '', $stripped));
        if ($stripped === '') {
            return [null, $currency];
        }
        $stripped = str_replace(',', '.', $stripped);
        if (preg_match('/^-?\d+(?:\.\d+)?$/', $stripped) !== 1) {
            return [null, $currency];
        }

        return [$stripped, $currency];
    }

    /**
     * @param list<string> $lines
     */
    private function parseCustomerBlock(array $lines, BookingExtranetData $data): void
    {
        $start = array_search('Jméno hosta', $lines, true);
        if ($start === false) {
            return;
        }

        $endLabels = ['Preferovaný jazyk', 'Kanál', 'Kanál:', 'IATA/TIDS kód', 'IATA/TIDS kód:'];
        $end = count($lines);
        foreach ($endLabels as $label) {
            $idx = array_search($label, $lines, true);
            if ($idx !== false && $idx > $start && $idx < $end) {
                $end = $idx;
            }
        }

        $block = array_slice($lines, $start + 1, $end - $start - 1);
        if ($block === []) {
            return;
        }

        $name = $this->cleanName($block[0]);
        if ($name !== null) {
            $data->guestName = $name;
        }

        foreach ($block as $line) {
            if ($data->guestEmail === null && preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', $line, $m) === 1) {
                $data->guestEmail = $m[0];
            }
            if ($data->guestPhone === null && preg_match('/\+\d[\d\s().-]{6,}/', $line, $m) === 1) {
                $phone = preg_replace('/[^\d+]/', '', $m[0]) ?? '';
                if (strlen($phone) >= 9) {
                    $data->guestPhone = $phone;
                }
            }
            // Kod zeme — Booking zobrazi vlajku + 2 lowercase pismena (fr, de, cz, sk, …)
            // jako vlastni radku v bloku po jmenu. Bereme prvni vyskyt.
            if ($data->guestCountry === null && preg_match('/^[a-z]{2}$/', $line) === 1) {
                $data->guestCountry = strtoupper($line);
            }
        }

        $addressLine = $this->detectAddress($block);
        if ($addressLine !== null) {
            [$street, $city, $zip] = $this->splitAddress($addressLine);
            $data->guestStreet = $street;
            $data->guestCity = $city;
            $data->guestZip = $zip;
        }
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function splitAddress(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [null, null, null];
        }

        $zip = null;
        // PSC na konci radku -> odriznout
        if (preg_match('/(\d{3})\s*(\d{2})\s*$/u', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            $zip = $m[1][0] . ' ' . $m[2][0];
            $line = trim(substr($line, 0, $m[0][1]));
        } elseif (preg_match('/(\d{3})\s*(\d{2})/u', $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            // PSC uprostred (Husova 5 110 00 Praha) -> pred=ulice, za=mesto
            $zip = $m[1][0] . ' ' . $m[2][0];
            $before = trim(substr($line, 0, $m[0][1]));
            $after = trim(substr($line, $m[0][1] + strlen($m[0][0])));
            if ($after !== '') {
                return [$before !== '' ? $before : null, $after, $zip];
            }
            $line = $before;
        }

        if ($line === '') {
            return [null, null, $zip];
        }

        $tokens = preg_split('/\s+/u', $line) ?: [];
        if (count($tokens) === 1) {
            return [$tokens[0], null, $zip];
        }

        // FR styl: prvni token je cislo popisne (napr. "10", "12bis").
        if (preg_match('/^\d+[a-zA-Z]?$/', $tokens[0]) === 1) {
            $cityTokens = [];
            for ($i = count($tokens) - 1; $i > 0; $i--) {
                if (preg_match('/^\p{Lu}[\p{Lu}\-]*$/u', $tokens[$i]) === 1) {
                    array_unshift($cityTokens, $tokens[$i]);
                } else {
                    break;
                }
            }
            if ($cityTokens === []) {
                $cityTokens = [(string) array_pop($tokens)];
            } else {
                $tokens = array_slice($tokens, 0, count($tokens) - count($cityTokens));
            }
            $street = implode(' ', $tokens);
            $city = implode(' ', $cityTokens);

            return [$street !== '' ? $street : null, $city !== '' ? $city : null, $zip];
        }

        // CZ styl: posledni token s cislici = konec ulice (cislo popisne/orientacni).
        // Vse za nim = mesto (vc. viceslovnych jako "Strelice u Brna").
        $lastDigitIdx = -1;
        foreach ($tokens as $i => $t) {
            if (preg_match('/\d/', $t) === 1) {
                $lastDigitIdx = $i;
            }
        }
        if ($lastDigitIdx === -1 || $lastDigitIdx === count($tokens) - 1) {
            return [$line, null, $zip];
        }

        $street = implode(' ', array_slice($tokens, 0, $lastDigitIdx + 1));
        $city = implode(' ', array_slice($tokens, $lastDigitIdx + 1));

        return [$street, $city, $zip];
    }

    private function cleanName(string $line): ?string
    {
        // Strip trailing " Genius" / " Genius Plus" loyalty markers, country flags etc.
        $line = (string) preg_replace('/\s+Genius(\s+\w+)?\s*$/u', '', $line);
        $line = trim($line);

        return $line !== '' ? $line : null;
    }

    /**
     * @param list<string> $block
     */
    private function detectAddress(array $block): ?string
    {
        // Heuristika: hledame radku s cislem a alespon dvema slovy, neni to email/telefon/2-pismenny country code/ instrukcni veta.
        foreach ($block as $line) {
            if (str_contains($line, '@')) {
                continue;
            }
            if (str_starts_with($line, '+')) {
                continue;
            }
            if (mb_strlen($line) <= 3) {
                continue;
            }
            if (str_starts_with($line, 'Spojte se') || str_starts_with($line, 'Také můžete') || str_contains($line, 'Stačí zavolat')) {
                continue;
            }
            // Adresa typicky obsahuje cislo (PSC nebo cislo popisne) a alespon jedno slovo.
            if (preg_match('/\d/', $line) !== 1) {
                continue;
            }
            if (preg_match('/[\p{L}]{3,}/u', $line) !== 1) {
                continue;
            }

            return $line;
        }

        return null;
    }
}
