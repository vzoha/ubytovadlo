<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Embeddable\ElectricityUsage;
use App\Entity\Embeddable\VatReverseCharge;
use App\Entity\Reservation;
use App\Enum\Channel;
use PHPUnit\Framework\TestCase;

/**
 * Settery hodnotových objektů touchnou `updatedAt` jen při reálné změně —
 * cron (rebalance elektřiny, přepočet DPH) je volá při každém běhu se stejnou
 * hodnotou a nesmí tím posouvat „naposledy změněno" ani generovat UPDATE.
 */
final class ReservationTest extends TestCase
{
    public function testSettingSameElectricityDoesNotTouch(): void
    {
        $r = new Reservation(Channel::DIRECT, new \DateTimeImmutable('2026-08-10'));
        $r->setElectricity(ElectricityUsage::allocated(32, 20));
        $before = $r->getUpdatedAt();

        $r->setElectricity(ElectricityUsage::allocated(32, 20));

        self::assertSame($before, $r->getUpdatedAt());
    }

    public function testSettingChangedElectricityTouches(): void
    {
        $r = new Reservation(Channel::DIRECT, new \DateTimeImmutable('2026-08-10'));
        $r->setElectricity(ElectricityUsage::allocated(32, 20));
        $before = $r->getUpdatedAt();

        $r->setElectricity(ElectricityUsage::allocated(40, 20));

        self::assertNotSame($before, $r->getUpdatedAt());
    }

    public function testSettingSameVatReverseChargeDoesNotTouch(): void
    {
        $r = new Reservation(Channel::AIRBNB, new \DateTimeImmutable('2026-08-10'));
        $vat = new VatReverseCharge(new \DateTimeImmutable('2026-08-10'), '25.00000000', new \DateTimeImmutable('2026-08-10'), '1000.00', '210.00');
        $r->setVatReverseCharge($vat);
        $before = $r->getUpdatedAt();

        $r->setVatReverseCharge(new VatReverseCharge(new \DateTimeImmutable('2026-08-10'), '25.00000000', new \DateTimeImmutable('2026-08-10'), '1000.00', '210.00'));

        self::assertSame($before, $r->getUpdatedAt());
    }
}
