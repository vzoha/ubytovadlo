<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class KernelTimezoneTest extends KernelTestCase
{
    public function testBootSetsAppTimezoneRegardlessOfPhpIni(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('UTC');

        try {
            self::bootKernel();
            self::assertSame(Kernel::TIMEZONE, date_default_timezone_get());
        } finally {
            date_default_timezone_set($original);
        }
    }
}
