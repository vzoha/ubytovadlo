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
 * Daňový profil dodavatele. Určuje, jak se na fakturách hostům zachází s DPH,
 * jestli se z provizí OTA počítá reverse charge a jaké texty se ukazují.
 *
 * - identified_person: neplátce s povinností odvádět DPH z přijatých služeb z EU
 *   (§6h/§6i ZDPH). Faktury hostům bez výstupní DPH, provize OTA = reverse charge.
 * - vat_payer: plátce DPH. Faktury s výstupní DPH, RC z provize s nárokem na odpočet.
 * - non_payer: neplátce bez jakékoli DPH agendy.
 */
enum TaxProfile: string
{
    case IDENTIFIED_PERSON = 'identified_person';
    case VAT_PAYER = 'vat_payer';
    case NON_PAYER = 'non_payer';

    public function label(): string
    {
        return match ($this) {
            self::IDENTIFIED_PERSON => 'Identifikovaná osoba',
            self::VAT_PAYER => 'Plátce DPH',
            self::NON_PAYER => 'Neplátce DPH',
        };
    }

    /** Vystavuje výstupní DPH na fakturách hostům. */
    public function chargesOutputVat(): bool
    {
        return $this === self::VAT_PAYER;
    }

    /** Odvádí reverse charge z přijatých provizí OTA (plátce i identifikovaná osoba). */
    public function reverseChargesCommission(): bool
    {
        return $this !== self::NON_PAYER;
    }
}
