<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

/** Proč dva zákazníky nabízíme ke sloučení. */
enum DuplicateReason: string
{
    case SAME_NAME = 'same_name';
    case SAME_EMAIL = 'same_email';
    case SAME_PHONE = 'same_phone';

    public function label(): string
    {
        return match ($this) {
            self::SAME_NAME => 'stejné jméno',
            self::SAME_EMAIL => 'stejný e-mail',
            self::SAME_PHONE => 'stejný telefon',
        };
    }
}
