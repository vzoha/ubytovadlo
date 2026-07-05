<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Invoice\IssuerProfileProvider;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class IssuerProfileProviderTest extends TestCase
{
    public function testReadsFromDb(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturnCallback(
            static fn (string $key): ?string => match ($key) {
                'invoice.issuer.name' => 'Malý Statek',
                'invoice.bank.iban' => 'CZ00',
                default => null,
            },
        );

        $issuer = $this->provider($settings)->current();

        self::assertSame('Malý Statek', $issuer->name);
        self::assertSame('CZ00', $issuer->bankAccountIban);
    }

    public function testEmptyWhenUnset(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturn(null);

        $issuer = $this->provider($settings)->current();

        self::assertSame('', $issuer->name);
        self::assertSame('', $issuer->bankAccount);
    }

    private function provider(SettingRepository $settings): IssuerProfileProvider
    {
        return new IssuerProfileProvider($settings);
    }
}
