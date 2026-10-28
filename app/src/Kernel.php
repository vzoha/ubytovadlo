<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Časy v DB jsou bez pásma, takže web i cron musí zapisovat ve stejném.
     * Nespoléhá na php.ini — sdílený hosting má pro CLI UTC a pro web Prahu.
     */
    public const TIMEZONE = 'Europe/Prague';

    public function boot(): void
    {
        date_default_timezone_set(self::TIMEZONE);
        parent::boot();
    }
}
