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
    public function testEmptyWhenNotSet(): void
    {
        $config = new InvoiceSeriesConfig($this->settings(null));

        self::assertSame([], $config->all());
        self::assertSame(1, $config->startForYear(2026));
    }

    public function testReadsMapFromDb(): void
    {
        $config = new InvoiceSeriesConfig($this->settings('{"2027":5}'));

        self::assertSame([2027 => 5], $config->all());
        self::assertSame(5, $config->startForYear(2027));
        self::assertSame(1, $config->startForYear(2026));
    }

    public function testInvalidJsonMeansEmpty(): void
    {
        $config = new InvoiceSeriesConfig($this->settings('not-json'));

        self::assertSame([], $config->all());
    }

    public function testEmptyMapMeansStartFromOne(): void
    {
        $config = new InvoiceSeriesConfig($this->settings('{}'));

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
