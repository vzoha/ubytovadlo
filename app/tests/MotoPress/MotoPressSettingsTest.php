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
    public function testReadsIdsAndPushFromDb(): void
    {
        $stored = [
            MotoPressSettings::KEY_PET => '10, 20',
            MotoPressSettings::KEY_BABY_COT => '866',
            MotoPressSettings::KEY_PUSH => '1',
        ];
        $motopress = new MotoPressSettings($this->settings($stored));

        self::assertSame([10, 20], $motopress->petServiceIds());
        self::assertSame([866], $motopress->babyCotServiceIds());
        self::assertTrue($motopress->pushPayments());
    }

    public function testEmptyWhenNotSet(): void
    {
        $motopress = new MotoPressSettings($this->settings([]));

        self::assertSame([], $motopress->petServiceIds());
        self::assertSame([], $motopress->babyCotServiceIds());
        self::assertFalse($motopress->pushPayments());
    }

    public function testCreateMapperUsesCurrentIds(): void
    {
        $motopress = new MotoPressSettings($this->settings([MotoPressSettings::KEY_PET => '925']));
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
