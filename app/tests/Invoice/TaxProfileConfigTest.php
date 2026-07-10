<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Enum\TaxProfile;
use App\Invoice\TaxProfileConfig;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class TaxProfileConfigTest extends TestCase
{
    public function testDefaultsToIdentifiedPerson(): void
    {
        self::assertSame(TaxProfile::IDENTIFIED_PERSON, $this->config([])->current());
    }

    public function testReadsStoredProfile(): void
    {
        $config = $this->config([TaxProfileConfig::KEY => 'vat_payer']);

        self::assertSame(TaxProfile::VAT_PAYER, $config->current());
    }

    public function testUnknownValueFallsBackToIdentifiedPerson(): void
    {
        self::assertSame(TaxProfile::IDENTIFIED_PERSON, $this->config([TaxProfileConfig::KEY => 'bogus'])->current());
    }

    /** @param array<string, string> $stored */
    private function config(array $stored): TaxProfileConfig
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $stored[$key] ?? $default,
        );

        return new TaxProfileConfig($settings);
    }
}
