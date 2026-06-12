<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Twig;

use App\Service\Cleaning\CleaningTypeLabeler;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class CleaningExtension extends AbstractExtension
{
    public function __construct(private readonly CleaningTypeLabeler $labeler)
    {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('cleaning_label', $this->labeler->label(...)),
        ];
    }
}
