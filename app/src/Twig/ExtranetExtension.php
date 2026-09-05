<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Twig;

use App\Reservation\ExtranetLink;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ExtranetExtension extends AbstractExtension
{
    public function __construct(private readonly ExtranetLink $links)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('extranet_url', $this->links->forReservation(...)),
        ];
    }
}
