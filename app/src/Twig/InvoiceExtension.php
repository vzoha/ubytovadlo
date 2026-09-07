<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Twig;

use App\Enum\PaymentMethod;
use App\Invoice\PaymentMethodLabeler;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class InvoiceExtension extends AbstractExtension
{
    public function __construct(private readonly PaymentMethodLabeler $labeler)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('payment_methods', PaymentMethod::cases(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('payment_label', $this->labeler->label(...)),
        ];
    }
}
