<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\DocumentReadSource;
use PHPUnit\Framework\TestCase;

final class DocumentReadSourceTest extends TestCase
{
    public function testKnownInputMapsToSource(): void
    {
        self::assertSame(DocumentReadSource::LIVE, DocumentReadSource::fromInput('live'));
    }

    public function testUnknownOrNonStringInputIsNull(): void
    {
        self::assertNull(DocumentReadSource::fromInput('<script>'));
        self::assertNull(DocumentReadSource::fromInput(''));
        self::assertNull(DocumentReadSource::fromInput(null));
        self::assertNull(DocumentReadSource::fromInput(['live']));
    }
}
