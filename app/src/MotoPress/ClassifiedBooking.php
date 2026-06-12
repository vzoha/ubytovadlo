<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\MotoPress;

final class ClassifiedBooking
{
    public function __construct(
        public readonly MotoPressBookingKind $kind,
        public readonly ?string $airbnbConfirmationCode = null,
    ) {
    }
}
