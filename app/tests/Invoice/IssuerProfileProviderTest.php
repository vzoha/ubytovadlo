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
    public function testFallsBackToEnvWhenDbEmpty(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturn(null);

        $issuer = $this->provider($settings)->current();

        self::assertSame('Env Name', $issuer->name);
        self::assertSame('CZ00', $issuer->bankAccountIban);
    }

    public function testDbOverridesEnv(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturnCallback(
            static fn (string $key): ?string => $key === 'invoice.issuer.name' ? 'Malý Statek' : null,
        );

        $issuer = $this->provider($settings)->current();

        self::assertSame('Malý Statek', $issuer->name);   // z DB
        self::assertSame('Env Street', $issuer->street);  // fallback z env
    }

    public function testEmptyDbValueFallsBack(): void
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturnCallback(
            static fn (string $key): ?string => $key === 'invoice.issuer.name' ? '' : null,
        );

        self::assertSame('Env Name', $this->provider($settings)->current()->name);
    }

    private function provider(SettingRepository $settings): IssuerProfileProvider
    {
        return new IssuerProfileProvider(
            $settings,
            'Env Name',
            'Env Street',
            'Env City',
            '10000',
            'CZ',
            '12345678',
            'CZ12345678',
            '+420 1',
            'env@example.com',
            'https://example.com',
            '1/0300',
            'CZ00',
        );
    }
}
