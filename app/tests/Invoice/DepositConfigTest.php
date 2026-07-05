<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Enum\BillingMode;
use App\Enum\DepositMode;
use App\Invoice\DepositConfig;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DepositConfigTest extends TestCase
{
    public function testDefaultsToFixedModeButNoAmountUntilSet(): void
    {
        $config = $this->config([]);

        self::assertSame(DepositMode::FIXED, $config->mode());
        self::assertTrue($config->enabled());
        // Bez nastavené výše se záloha nevystaví.
        self::assertNull($config->computeAmount(null));
        self::assertSame(DepositConfig::DEFAULT_DUE_DAYS, $config->dueDays());
    }

    public function testFixedAmountFromDb(): void
    {
        $config = $this->config([
            DepositConfig::KEY_MODE => 'fixed',
            DepositConfig::KEY_VALUE => '1500',
        ]);

        self::assertSame('1500.00', $config->computeAmount('9000'));
    }

    public function testPercentComputesFromReservationTotal(): void
    {
        $config = $this->config([
            DepositConfig::KEY_MODE => 'percent',
            DepositConfig::KEY_VALUE => '30',
        ]);

        self::assertSame('1500.00', $config->computeAmount('5000'));
        // Bez ceny nelze procento spočítat.
        self::assertNull($config->computeAmount(null));
    }

    public function testDepositIsCappedAtReservationPrice(): void
    {
        // Fixní 1000 na levném pobytu 800 → záloha nepřesáhne cenu (jinak záporný doplatek).
        $fixed = $this->config([DepositConfig::KEY_VALUE => '1000']);
        self::assertSame('800.00', $fixed->computeAmount('800'));

        // Procento nad 100 se taky zastropuje na cenu.
        $percent = $this->config([DepositConfig::KEY_MODE => 'percent', DepositConfig::KEY_VALUE => '150']);
        self::assertSame('5000.00', $percent->computeAmount('5000'));
    }

    public function testNonPositiveOrNonNumericValueYieldsNoAmount(): void
    {
        self::assertNull($this->config([DepositConfig::KEY_VALUE => '0'])->computeAmount('5000'));
        self::assertNull($this->config([DepositConfig::KEY_VALUE => '-10'])->computeAmount('5000'));
        self::assertNull($this->config([DepositConfig::KEY_VALUE => 'abc'])->computeAmount('5000'));
    }

    public function testNoneDisablesDeposit(): void
    {
        $config = $this->config([DepositConfig::KEY_MODE => 'none']);

        self::assertFalse($config->enabled());
        self::assertNull($config->computeAmount('5000'));
        self::assertFalse($config->appliesTo(BillingMode::STANDARD_WITH_DEPOSIT));
    }

    public function testAppliesOnlyToDepositModeWhenEnabled(): void
    {
        $fixed = $this->config([]);
        self::assertTrue($fixed->appliesTo(BillingMode::STANDARD_WITH_DEPOSIT));
        self::assertFalse($fixed->appliesTo(BillingMode::FKSP));
        self::assertFalse($fixed->appliesTo(BillingMode::AIRBNB));
        self::assertFalse($fixed->appliesTo(null));
    }

    public function testDueDaysFromDbAndNegativeFallsBack(): void
    {
        self::assertSame(5, $this->config([DepositConfig::KEY_DUE_DAYS => '5'])->dueDays());
        self::assertSame(0, $this->config([DepositConfig::KEY_DUE_DAYS => '0'])->dueDays());
        self::assertSame(DepositConfig::DEFAULT_DUE_DAYS, $this->config([DepositConfig::KEY_DUE_DAYS => '-3'])->dueDays());
    }

    public function testUnknownModeFallsBackToFixed(): void
    {
        self::assertSame(DepositMode::FIXED, $this->config([DepositConfig::KEY_MODE => 'bogus'])->mode());
    }

    /** @param array<string, string> $stored */
    private function config(array $stored): DepositConfig
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $stored[$key] ?? $default,
        );
        $settings->method('getInt')->willReturnCallback(
            static fn (string $key, int $default = 0): int => isset($stored[$key]) ? (int) $stored[$key] : $default,
        );

        return new DepositConfig($settings);
    }
}
