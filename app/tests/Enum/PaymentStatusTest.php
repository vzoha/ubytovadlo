<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\PaymentStatus;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    public function testUnpaidWhenNothingReceived(): void
    {
        self::assertSame(PaymentStatus::UNPAID, PaymentStatus::fromAmounts(5000.0, 0.0));
    }

    public function testPartialWhenSomeReceived(): void
    {
        self::assertSame(PaymentStatus::PARTIAL, PaymentStatus::fromAmounts(5000.0, 1000.0));
    }

    public function testPaidWhenFullyReceived(): void
    {
        self::assertSame(PaymentStatus::PAID, PaymentStatus::fromAmounts(5000.0, 5000.0));
    }

    public function testPaidWhenOverpaid(): void
    {
        self::assertSame(PaymentStatus::PAID, PaymentStatus::fromAmounts(5000.0, 5200.0));
    }

    public function testRoundingTolerance(): void
    {
        self::assertSame(PaymentStatus::PAID, PaymentStatus::fromAmounts(5000.004, 5000.0));
    }
}
