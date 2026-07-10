<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

/**
 * Sazby výstupní DPH na fakturách hostům (plátce DPH). Ukládají se na řádek faktury,
 * takže historické faktury drží sazbu platnou v době vystavení i po pozdější změně.
 *
 * Vstupní reverse charge z provizí OTA má vlastní sazbu (VatCalculator::VAT_RATE) —
 * je to jiný daňový svět a nesdílí se.
 */
final class VatRates
{
    /** Ubytovací služby — snížená sazba. */
    public const ACCOMMODATION = '12.00';

    /** Standardní sazba — doplňkové služby a zboží. */
    public const STANDARD = '21.00';
}
