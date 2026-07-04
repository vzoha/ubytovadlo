<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Invoice\InvoiceSeriesConfig;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class InvoiceSeriesConfigTest extends TestCase
{
    public function testFallsBackToEnvWhenDbEmpty(): void
    {
        $config = new InvoiceSeriesConfig($this->settings(null), [2026 => 12]);

        self::assertSame([2026 => 12], $config->all());
        self::assertSame(12, $config->startForYear(2026));
        self::assertSame(1, $config->startForYear(2027));
    }

    public function testDbOverridesEnv(): void
    {
        $config = new InvoiceSeriesConfig($this->settings('{"2027":5}'), [2026 => 12]);

        self::assertSame([2027 => 5], $config->all());
        self::assertSame(5, $config->startForYear(2027));
        self::assertSame(1, $config->startForYear(2026));
    }

    public function testInvalidJsonFallsBackToEnv(): void
    {
        $config = new InvoiceSeriesConfig($this->settings('not-json'), [2026 => 12]);

        self::assertSame([2026 => 12], $config->all());
    }

    public function testEmptyMapMeansStartFromOne(): void
    {
        $config = new InvoiceSeriesConfig($this->settings('{}'), [2026 => 12]);

        self::assertSame([], $config->all());
        self::assertSame(1, $config->startForYear(2026));
    }

    private function settings(?string $stored): SettingRepository
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturn($stored);

        return $settings;
    }
}
