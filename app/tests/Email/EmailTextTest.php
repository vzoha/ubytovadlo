<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Email;

use App\Email\EmailText;
use PHPUnit\Framework\TestCase;

final class EmailTextTest extends TestCase
{
    public function testNbspAndNarrowNbspBecomeSpace(): void
    {
        $input = "1\xc2\xa0234\xe2\x80\xafKč";

        self::assertSame('1 234 Kč', EmailText::normalizeWhitespace($input));
    }

    public function testCollapsesSpacesAndTabs(): void
    {
        self::assertSame('a b c', EmailText::normalizeWhitespace("a  \t b    c"));
    }

    public function testTrimsEdges(): void
    {
        self::assertSame('text', EmailText::normalizeWhitespace('   text   '));
    }
}
