<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Config;

use App\Config\GuestRegistrationSettings;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class GuestRegistrationSettingsTest extends TestCase
{
    public function testEnabledByDefaultWhenUnset(): void
    {
        $settings = new GuestRegistrationSettings($this->settings([]));

        self::assertTrue($settings->registerCzechGuests());
        self::assertSame(['registerCzechGuests' => true], $settings->currentValues());
    }

    public function testDisabledWhenSetToZero(): void
    {
        $settings = new GuestRegistrationSettings(
            $this->settings([GuestRegistrationSettings::KEY_REGISTER_CZECH => '0']),
        );

        self::assertFalse($settings->registerCzechGuests());
    }

    public function testEnabledWhenSetToOne(): void
    {
        $settings = new GuestRegistrationSettings(
            $this->settings([GuestRegistrationSettings::KEY_REGISTER_CZECH => '1']),
        );

        self::assertTrue($settings->registerCzechGuests());
    }

    /** @param array<string, string> $stored */
    private function settings(array $stored): SettingRepository
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturnCallback(
            static fn (string $key): ?string => $stored[$key] ?? null,
        );

        return $settings;
    }
}
