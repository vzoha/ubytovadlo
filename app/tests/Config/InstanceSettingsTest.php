<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\InstanceSettings;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class InstanceSettingsTest extends TestCase
{
    public function testReadsFromDb(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                InstanceSettings::KEY_BRAND_NAME => 'Penzion U Lesa',
                InstanceSettings::KEY_BASE_URL => 'https://app.example',
                default => null,
            },
        );

        $instance = new InstanceSettings($settings);

        self::assertSame('Penzion U Lesa', $instance->brandName());
        self::assertSame('https://app.example', $instance->baseUrl());
    }

    public function testDefaultBrandAndEmptyBaseUrlWhenUnset(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturn(null);

        $instance = new InstanceSettings($settings);

        self::assertSame('Ubytovadlo', $instance->brandName());
        self::assertSame('', $instance->baseUrl());
    }
}
