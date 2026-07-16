<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Entity\Embeddable;

use App\Entity\Embeddable\UbyportReport;
use PHPUnit\Framework\TestCase;

final class UbyportReportTest extends TestCase
{
    public function testExportedSetsExportedAt(): void
    {
        $at = new \DateTimeImmutable('2026-05-10 08:00');
        $report = (new UbyportReport())->exported($at);

        self::assertSame($at, $report->getExportedAt());
        self::assertNull($report->getConfirmedAt());
    }

    public function testConfirmedWithReceipt(): void
    {
        $exportedAt = new \DateTimeImmutable('2026-05-10 08:00');
        $confirmedAt = new \DateTimeImmutable('2026-05-11 09:00');
        $report = (new UbyportReport())->exported($exportedAt)->confirmed($confirmedAt, 'dorucenka.xml', 3, 1);

        self::assertSame($exportedAt, $report->getExportedAt());
        self::assertSame($confirmedAt, $report->getConfirmedAt());
        self::assertSame('dorucenka.xml', $report->getReceiptFilename());
        self::assertSame(3, $report->getReceiptAccepted());
        self::assertSame(1, $report->getReceiptRejected());
    }

    public function testConfirmedBackfillsExportWhenMissing(): void
    {
        $at = new \DateTimeImmutable('2026-05-11 09:00');
        $report = (new UbyportReport())->confirmed($at);

        self::assertSame($at, $report->getExportedAt(), 'ruční potvrzení bez exportu dorovná export na stejný čas');
        self::assertSame($at, $report->getConfirmedAt());
    }

    public function testEmptyIsReset(): void
    {
        $report = new UbyportReport();

        self::assertNull($report->getExportedAt());
        self::assertNull($report->getConfirmedAt());
        self::assertNull($report->getReceiptFilename());
    }
}
