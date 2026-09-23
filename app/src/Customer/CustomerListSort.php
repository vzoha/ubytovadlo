<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Customer;

/** Řazení seznamu hostů; hodnota je parametr `razeni` v URL. */
enum CustomerListSort: string
{
    case LAST_CHECK_IN = 'prijezd';
    case INCOME = 'prijem';

    public function label(): string
    {
        return match ($this) {
            self::LAST_CHECK_IN => 'Poslední příjezd',
            self::INCOME => 'Příjem',
        };
    }
}
