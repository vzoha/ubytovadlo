<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Mail;

use App\Mail\GuestVocative;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GuestVocativeTest extends TestCase
{
    #[DataProvider('names')]
    public function testFirstName(?string $input, string $expected): void
    {
        self::assertSame($expected, (new GuestVocative())->firstName($input));
    }

    /** @return iterable<string, array{0: ?string, 1: string}> */
    public static function names(): iterable
    {
        yield 'mužské jméno' => ['Petr', 'Petře'];
        yield 'jen křestní z celého jména' => ['Jan Novák', 'Jane'];
        yield 'ženské jméno' => ['Eva', 'Evo'];
        yield 'jméno bez změny v 5. pádu' => ['Jiří', 'Jiří'];
        yield 'prázdný vstup nedá „E"' => ['', ''];
        yield 'null' => [null, ''];
        yield 'jen mezery' => ['   ', ''];
    }

    #[DataProvider('surnames')]
    public function testLastName(?string $input, string $expected): void
    {
        self::assertSame($expected, (new GuestVocative())->lastName($input));
    }

    /** @return iterable<string, array{0: ?string, 1: string}> */
    public static function surnames(): iterable
    {
        yield 'mužské příjmení' => ['Jan Novák', 'Nováku'];
        yield 'ženské příjmení beze změny' => ['Eva Nováková', 'Nováková'];
        yield 'příjmení na -a' => ['Petr Svoboda', 'Svobodo'];
        yield 'poslední slovo z víceslovného' => ['Anna Marie Dvořák', 'Dvořáku'];
        yield 'jednoslovné jméno nemá příjmení' => ['Jan', ''];
        yield 'prázdný vstup' => ['', ''];
        yield 'null' => [null, ''];
    }
}
