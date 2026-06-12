<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\MotoPress;

enum MotoPressBookingKind
{
    case WEB;
    case IMPORTED_AIRBNB;
    case IMPORTED_BOOKING;
    case IMPORTED_UNKNOWN;
}
