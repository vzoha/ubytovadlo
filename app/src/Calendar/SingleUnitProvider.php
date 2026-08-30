<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Calendar;

use App\Config\InstanceSettings;
use App\Entity\Reservation;

/**
 * Jedno ubytování pojmenované značkou instance — všechny rezervace spadají
 * do jeho řádku.
 */
final readonly class SingleUnitProvider implements UnitProvider
{
    public const UNIT_ID = 'default';

    public function __construct(private InstanceSettings $settings)
    {
    }

    public function units(): array
    {
        return [new CalendarUnit(self::UNIT_ID, $this->settings->brandName())];
    }

    public function unitIdFor(Reservation $reservation): string
    {
        return self::UNIT_ID;
    }
}
