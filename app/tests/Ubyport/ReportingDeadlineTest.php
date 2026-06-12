<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Ubyport;

use App\Ubyport\ReportingDeadline;
use PHPUnit\Framework\TestCase;

final class ReportingDeadlineTest extends TestCase
{
    private ReportingDeadline $deadline;

    protected function setUp(): void
    {
        $this->deadline = new ReportingDeadline();
    }

    public function testDeadlineSkipsWeekend(): void
    {
        // Pátek 2026-06-12 + 3 prac. dny → po(15), út(16), st(17) = středa.
        $checkIn = new \DateTimeImmutable('2026-06-12');
        self::assertSame('2026-06-17', $this->deadline->deadlineFor($checkIn)->format('Y-m-d'));
    }

    public function testDeadlineWithinWeek(): void
    {
        // Pondělí 2026-06-15 + 3 prac. dny → út, st, čt = čtvrtek 18.
        $checkIn = new \DateTimeImmutable('2026-06-15');
        self::assertSame('2026-06-18', $this->deadline->deadlineFor($checkIn)->format('Y-m-d'));
    }

    public function testStateOverdue(): void
    {
        $deadline = new \DateTimeImmutable('2026-06-18');
        self::assertSame('overdue', $this->deadline->state($deadline, new \DateTimeImmutable('2026-06-19')));
    }

    public function testStateDueSoon(): void
    {
        $deadline = new \DateTimeImmutable('2026-06-18');
        self::assertSame('due_soon', $this->deadline->state($deadline, new \DateTimeImmutable('2026-06-17')));
        self::assertSame('due_soon', $this->deadline->state($deadline, new \DateTimeImmutable('2026-06-18')));
    }

    public function testStateOk(): void
    {
        $deadline = new \DateTimeImmutable('2026-06-18');
        self::assertSame('ok', $this->deadline->state($deadline, new \DateTimeImmutable('2026-06-15')));
    }

    public function testDaysLeftSigned(): void
    {
        $deadline = new \DateTimeImmutable('2026-06-18');
        self::assertSame(3, $this->deadline->daysLeft($deadline, new \DateTimeImmutable('2026-06-15')));
        self::assertSame(-2, $this->deadline->daysLeft($deadline, new \DateTimeImmutable('2026-06-20')));
    }
}
