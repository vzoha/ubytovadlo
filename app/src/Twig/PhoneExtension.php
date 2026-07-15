<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Twig;

use App\ValueObject\PhoneNumber;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class PhoneExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('phone', $this->phone(...)),
        ];
    }

    /**
     * Vrátí telefon jako value object pro zobrazení a deep linky (tel:, sms:, wa.me),
     * nebo null, když číslo nejde naparsovat. V šabloně: {{ (reservation.guestPhone|phone).national }}.
     */
    public function phone(?string $raw): ?PhoneNumber
    {
        return PhoneNumber::tryFromString($raw);
    }
}
