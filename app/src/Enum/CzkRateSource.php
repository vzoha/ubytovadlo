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
 * Odkud pochází kurz použitý pro náhled ceny v korunách. INVOICE a RESERVATION
 * jsou kurzy uložené k danému dni (fakturovaná, resp. daňová hodnota), DAILY je
 * dnešní kurz ČNB — jen orientace, než rezervace dostane svůj vlastní kurz.
 */
enum CzkRateSource: string
{
    case INVOICE = 'invoice';
    case RESERVATION = 'reservation';
    case DAILY = 'daily';

    public function label(): string
    {
        return match ($this) {
            self::INVOICE => 'Kurz z faktury',
            self::RESERVATION => 'Kurz ČNB k datu plnění',
            self::DAILY => 'Orientačně denním kurzem ČNB',
        };
    }

    /** Denní kurz je jen odhad — cena se zúčtuje kurzem, který teprve vznikne. */
    public function isEstimate(): bool
    {
        return $this === self::DAILY;
    }
}
