<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Entity\Embeddable;

use App\Entity\Embeddable\ElectricityUsage;
use App\Enum\ElectricitySource;
use PHPUnit\Framework\TestCase;

final class ElectricityUsageTest extends TestCase
{
    public function testMeasuredFactory(): void
    {
        $usage = ElectricityUsage::measured(120, 60);

        self::assertSame(120, $usage->getVtKwh());
        self::assertSame(60, $usage->getNtKwh());
        self::assertSame(ElectricitySource::MEASURED, $usage->getSource());
        self::assertSame(180, $usage->getTotalKwh());
    }

    public function testAllocatedFactory(): void
    {
        $usage = ElectricityUsage::allocated(90, 30);

        self::assertSame(ElectricitySource::ALLOCATED, $usage->getSource());
        self::assertSame(120, $usage->getTotalKwh());
    }

    public function testEmptyHasNoTotal(): void
    {
        $usage = new ElectricityUsage();

        self::assertNull($usage->getTotalKwh());
        self::assertNull($usage->getSource());
    }

    public function testTotalWithSingleReading(): void
    {
        self::assertSame(50, (new ElectricityUsage(50, null))->getTotalKwh());
        self::assertSame(20, (new ElectricityUsage(null, 20))->getTotalKwh());
    }

    public function testEquals(): void
    {
        $usage = ElectricityUsage::measured(32, 20);

        self::assertTrue($usage->equals(ElectricityUsage::measured(32, 20)));
        self::assertFalse($usage->equals(ElectricityUsage::allocated(32, 20)), 'jiný zdroj = jiná spotřeba');
        self::assertFalse($usage->equals(ElectricityUsage::measured(33, 20)));
    }
}
