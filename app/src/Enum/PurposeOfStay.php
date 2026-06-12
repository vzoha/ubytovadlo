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
 * Číselník účelů pobytu pro Ubyport (sources/ubyport/ucel_pobytu_kod.csv).
 * Kód je string — '01' != '1'.
 */
enum PurposeOfStay: string
{
    case ZDRAVOTNI = '00';
    case OBCHODNI = '01';
    case KULTURNI = '02';
    case NAVSTEVA_RODINY = '03';
    case POZVANI = '04';
    case OFICIALNI = '05';
    case PODNIKANI_OSVC = '06';
    case SPORTOVNI = '07';
    case TURISTIKA = '10';
    case STUDIUM = '11';
    case TRANZIT = '12';
    case LETISTNI_TRANZIT = '13';
    case ZAMESTNANI = '27';
    case ADS_VIZUM = '93';
    case OSTATNI = '99';

    public function label(): string
    {
        return match ($this) {
            self::ZDRAVOTNI => 'Zdravotní',
            self::OBCHODNI => 'Obchodní',
            self::KULTURNI => 'Kulturní',
            self::NAVSTEVA_RODINY => 'Návštěva rodiny nebo přátel',
            self::POZVANI => 'Pozvání',
            self::OFICIALNI => 'Oficiální (politický)',
            self::PODNIKANI_OSVC => 'Podnikání – OSVČ',
            self::SPORTOVNI => 'Sportovní',
            self::TURISTIKA => 'Turistika',
            self::STUDIUM => 'Studium (školení, stáž)',
            self::TRANZIT => 'Tranzit (průjezd)',
            self::LETISTNI_TRANZIT => 'Letištní tranzit',
            self::ZAMESTNANI => 'Zaměstnání',
            self::ADS_VIZUM => 'ADS vízum (občané Číny)',
            self::OSTATNI => 'Ostatní / jiné',
        };
    }
}
