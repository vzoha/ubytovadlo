<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Customer;

use App\Customer\NameMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NameMatchTest extends TestCase
{
    /** @return iterable<string, array{?string, ?string, bool}> */
    public static function pairs(): iterable
    {
        yield 'stejné jméno' => ['Jan Novák', 'Jan Novák', true];
        yield 'bez diakritiky a velikosti' => ['Jan Novák', 'jan novak', true];
        yield 'obrácené pořadí' => ['Novák Jan', 'Jan Novák', true];
        yield 'přechýlení -ová' => ['Jan Novák', 'Petra Nováková', true];
        yield 'přechýlení Kučera' => ['Pavel Kučera', 'Eva Kučerová', true];
        yield 'jiní lidé' => ['Jan Novák', 'Marie Dvořáková', false];
        yield 'podobný začátek nestačí' => ['Jan Novák', 'Eva Novotná', false];
        yield 'iniciála se nepočítá' => ['J. Novák', 'J. Dvořák', false];
        yield 'chybějící jméno nerozhoduje' => [null, 'Jan Novák', true];
        yield 'prázdné jméno nerozhoduje' => ['Jan Novák', '  ', true];
    }

    #[DataProvider('pairs')]
    public function testCompatible(?string $a, ?string $b, bool $expected): void
    {
        self::assertSame($expected, NameMatch::compatible($a, $b));
        self::assertSame($expected, NameMatch::compatible($b, $a), 'symetrické');
    }
}
