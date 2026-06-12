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
 * Jak byla spotřeba VT/NT na rezervaci určena.
 * - allocated: rozpočítáno z odečtu pokrývajícího víc rezervací (sezónní profil)
 * - measured: rezervace má vlastní odečty před+po (přesné).
 */
enum ElectricitySource: string
{
    case ALLOCATED = 'allocated';
    case MEASURED = 'measured';
}
