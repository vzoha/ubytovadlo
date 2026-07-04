<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Invoice\InvoiceNumberFormat;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class InvoiceNumberFormatTest extends TestCase
{
    #[DataProvider('renderCases')]
    public function testRender(string $pattern, int $year, int $seq, string $expected): void
    {
        self::assertSame($expected, InvoiceNumberFormat::render($pattern, $year, $seq));
    }

    /** @return iterable<string, array{string, int, int, string}> */
    public static function renderCases(): iterable
    {
        yield 'výchozí' => ['{RRRR}{NNN}', 2026, 12, '2026012'];
        yield 'jednociferné pořadí' => ['{RRRR}{NNN}', 2027, 1, '2027001'];
        yield 'prefix a oddělovače' => ['FA-{RRRR}-{NNN}', 2026, 12, 'FA-2026-012'];
        yield 'dvouciferný rok' => ['{RR}{NNNN}', 2026, 12, '260012'];
        yield 'lomítko' => ['{RRRR}/{NN}', 2026, 7, '2026/07'];
    }

    #[DataProvider('validityCases')]
    public function testIsValid(string $pattern, bool $valid): void
    {
        self::assertSame($valid, InvoiceNumberFormat::isValid($pattern));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function validityCases(): iterable
    {
        yield 'výchozí platný' => ['{RRRR}{NNN}', true];
        yield 'prefix platný' => ['FA-{RRRR}-{NNN}', true];
        yield 'bez roku' => ['{NNN}', false];
        yield 'bez pořadí' => ['{RRRR}', false];
        yield 'nebezpečné znaky' => ['{RRRR}{NNN}<script>', false];
        yield 'prázdný' => ['', false];
    }

    public function testPatternFallsBackWhenDbEmpty(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturn(null);

        self::assertSame('{RRRR}{NNN}', (new InvoiceNumberFormat($settings))->pattern());
    }

    public function testPatternIgnoresInvalidDbValue(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturn('{NNN}'); // neplatný (bez roku)

        self::assertSame('{RRRR}{NNN}', (new InvoiceNumberFormat($settings))->pattern());
    }

    public function testPatternUsesValidDbValue(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturn('FA-{RRRR}-{NNN}');

        $format = new InvoiceNumberFormat($settings);
        self::assertSame('FA-{RRRR}-{NNN}', $format->pattern());
        self::assertSame('FA-2026-012', $format->format(2026, 12));
    }
}
