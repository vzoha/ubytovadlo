<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Formatting;

use App\Formatting\Money;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    #[DataProvider('normalizeCases')]
    public function testNormalize(float|int|string|null $input, string $expected): void
    {
        self::assertSame($expected, Money::normalize($input));
    }

    /**
     * @return iterable<string, array{float|int|string|null, string}>
     */
    public static function normalizeCases(): iterable
    {
        yield 'float' => [1234.5, '1234.50'];
        yield 'int' => [1000, '1000.00'];
        yield 'decimal string' => ['999.9', '999.90'];
        yield 'null je nula' => [null, '0.00'];
        yield 'zaokrouhlení nahoru' => [1.239, '1.24'];
    }

    public function testNormalizeScale(): void
    {
        self::assertSame('1234.5', Money::normalize(1234.5, 1));
        self::assertSame('1235', Money::normalize(1234.5, 0));
    }

    #[DataProvider('parseCases')]
    public function testParse(?string $input, ?string $expected): void
    {
        self::assertSame($expected, Money::parse($input));
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function parseCases(): iterable
    {
        yield 'český formát s mezerami a čárkou' => ['1 234,50', '1234.50'];
        yield 'nbsp jako oddělovač tisíců' => ["1\u{00a0}234,50", '1234.50'];
        yield 'tečka jako desetinná' => ['1234.5', '1234.50'];
        yield 'celé číslo' => ['1000', '1000.00'];
        yield 'okolní mezery' => ['  500  ', '500.00'];
        yield 'prázdný vstup' => ['', null];
        yield 'null' => [null, null];
        yield 'nečíslo' => ['abc', null];
    }

    #[DataProvider('symbolCases')]
    public function testSymbol(?string $currency, string $expected): void
    {
        self::assertSame($expected, Money::symbol($currency));
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public static function symbolCases(): iterable
    {
        yield 'CZK jako Kč' => ['CZK', 'Kč'];
        yield 'EUR jako ISO' => ['EUR', 'EUR'];
        yield 'null je prázdno' => [null, ''];
    }

    public function testFormat(): void
    {
        self::assertSame('1 234,50 Kč', Money::format(1234.5));
        self::assertSame('1 234,50 EUR', Money::format('1234.50', 'EUR'));
        self::assertSame('0 Kč', Money::format(null, 'CZK', 0));
    }
}
