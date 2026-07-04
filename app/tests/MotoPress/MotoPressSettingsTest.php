<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\MotoPress;

use App\MotoPress\MotoPressSettings;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class MotoPressSettingsTest extends TestCase
{
    public function testFallsBackToEnvWhenDbEmpty(): void
    {
        $motopress = new MotoPressSettings($this->settings([]), ['925'], ['866'], true);

        self::assertSame([925], $motopress->petServiceIds());
        self::assertSame([866], $motopress->babyCotServiceIds());
        self::assertTrue($motopress->pushPayments());
    }

    public function testDbOverridesEnv(): void
    {
        $stored = [
            MotoPressSettings::KEY_PET => '10, 20',
            MotoPressSettings::KEY_PUSH => '0',
        ];
        $motopress = new MotoPressSettings($this->settings($stored), ['925'], ['866'], true);

        self::assertSame([10, 20], $motopress->petServiceIds());   // z DB
        self::assertSame([866], $motopress->babyCotServiceIds());  // fallback z env
        self::assertFalse($motopress->pushPayments());             // z DB
    }

    public function testEmptyDbValueMeansNoIds(): void
    {
        $motopress = new MotoPressSettings($this->settings([MotoPressSettings::KEY_PET => '']), ['925'], [], false);

        self::assertSame([], $motopress->petServiceIds());
    }

    public function testCreateMapperUsesCurrentIds(): void
    {
        $motopress = new MotoPressSettings($this->settings([MotoPressSettings::KEY_PET => '925']), [], [], false);
        $mapper = $motopress->createMapper();

        $reservation = new \App\Entity\Reservation(\App\Enum\Channel::WEB, new \DateTimeImmutable('2026-07-10'));
        $mapper->applyWebBooking($reservation, [
            'status' => 'confirmed',
            'check_in_date' => '2026-07-10',
            'check_out_date' => '2026-07-14',
            'services' => [['id' => 925, 'title' => 'Pes', 'qty' => 1]],
        ]);

        self::assertTrue($reservation->hasPet());
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
