<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Ical\Import;

/** Stažení iCal feedu selhalo (nedostupný, chybný HTTP status, transport). */
final class IcalFeedException extends \RuntimeException
{
}
