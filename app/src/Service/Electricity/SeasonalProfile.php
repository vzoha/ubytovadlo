<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Service\Electricity;

/**
 * Měsíční váhový faktor spotřeby elektřiny (relativní k celoročnímu mediánu).
 * Naučeno z historických CSV 2023–2026 (61 rezervací s vyplněnou spotřebou,
 * medián 42.5 kWh/noc). Hodnoty jsou bezrozměrné — slouží jen jako relativní
 * váha při rozpočítání spotřeby mezi rezervace.
 *
 * Únor v datech chybí (žádná rezervace s odečtem) — dopočten průměrem
 * leden+březen jako rozumný odhad zimního měsíce.
 *
 * @see sources/learn_electricity_profile.py — generátor
 */
final class SeasonalProfile
{
    /** @var array<int, float> */
    private const FACTORS = [
        1 => 2.64,   // leden
        2 => 2.69,   // únor (interpolováno z 1+3)
        3 => 2.73,   // březen
        4 => 1.00,   // duben
        5 => 1.06,   // květen
        6 => 0.35,   // červen
        7 => 0.24,   // červenec
        8 => 0.25,   // srpen
        9 => 0.65,   // září
        10 => 0.94,  // říjen
        11 => 2.51,  // listopad
        12 => 2.02,  // prosinec
    ];

    /**
     * Hosté ≥3 spotřebují cca o 20 % víc — po odečtu lineárního vlivu venkovní
     * teploty vychází z historických dat rozdíl +14 kWh/noc oproti pobytům
     * 1–2 hostů (n=35 vs n=26). Druhá místnost se temperuje navíc.
     *
     * @see sources/temperature_vs_consumption.py
     */
    private const LARGE_GROUP_FACTOR = 1.20;

    public static function factorForMonth(int $month): float
    {
        return self::FACTORS[$month] ?? 1.0;
    }

    public static function factorForGuests(int $guests): float
    {
        return $guests >= 3 ? self::LARGE_GROUP_FACTOR : 1.0;
    }

    /**
     * Váha rezervace pro alokaci spotřeby. Pobyt přes víc měsíců dostane
     * průměr faktorů přes všechny noci, násobeno faktorem podle počtu hostů.
     */
    public static function weightForStay(\DateTimeImmutable $checkIn, \DateTimeImmutable $checkOut, int $guests = 0): float
    {
        $nights = (int) $checkIn->diff($checkOut)->days;
        if ($nights <= 0) {
            return 0.0;
        }
        $sum = 0.0;
        $day = $checkIn;
        for ($i = 0; $i < $nights; $i++) {
            $sum += self::factorForMonth((int) $day->format('n'));
            $day = $day->modify('+1 day');
        }

        return $sum * self::factorForGuests($guests);
    }
}
