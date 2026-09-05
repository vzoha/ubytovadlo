<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Config;

use App\Repository\AccommodationProfileRepository;

/**
 * Název ubytování, jak ho zná host — jediné jméno, které se mu ukazuje ve zprávách
 * i na stránkách check-inu. Dokud není vyplněný, zastoupí ho název instance.
 */
final class PropertyName implements \Stringable
{
    public function __construct(
        private readonly AccommodationProfileRepository $profiles,
        private readonly BrandName $brandName,
    ) {
    }

    public function __toString(): string
    {
        $nazev = $this->profiles->getSingleton()?->getNazev() ?? '';

        return $nazev !== '' ? $nazev : (string) $this->brandName;
    }
}
