<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Twig;

use App\Checkin\CheckinWindow;
use App\Entity\Reservation;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Platnost odkazu na online check-in pro detail rezervace:
 * {{ checkin_open(reservation) }}, {{ checkin_closes_on(reservation)|date }}.
 */
class CheckinExtension extends AbstractExtension
{
    public function __construct(private readonly CheckinWindow $window)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('checkin_open', $this->window->allowsToken(...)),
            new TwigFunction('checkin_closes_on', $this->window->closesOn(...)),
        ];
    }
}
