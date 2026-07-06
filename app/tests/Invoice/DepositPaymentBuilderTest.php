<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Invoice\DepositConfig;
use App\Invoice\DepositPaymentBuilder;
use App\Invoice\IssuerProfileProvider;
use App\Invoice\SpaydGenerator;
use App\Repository\SettingRepository;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class DepositPaymentBuilderTest extends TestCase
{
    public function testBuildsPaymentWithQrForDepositFlow(): void
    {
        $builder = $this->builder([
            DepositConfig::KEY_VALUE => '1000',
            IssuerProfileProvider::KEYS['bankAccount'] => '1861547133/0800',
        ]);

        $deposit = $builder->forReservation($this->reservation(BillingMode::STANDARD_WITH_DEPOSIT), new \DateTimeImmutable('2026-07-06'));

        self::assertNotNull($deposit);
        self::assertSame('1000.00', $deposit->amount);
        self::assertSame('1760', $deposit->variableSymbol);
        self::assertSame('1861547133/0800', $deposit->bankAccount);
        self::assertSame('2026-07-08', $deposit->dueDate->format('Y-m-d'));
        self::assertNotNull($deposit->spayd);
        self::assertStringContainsString('X-VS:1760', (string) $deposit->spayd);
    }

    public function testNoQrWithoutBankAccount(): void
    {
        $builder = $this->builder([DepositConfig::KEY_VALUE => '1000']);

        $deposit = $builder->forReservation($this->reservation(BillingMode::STANDARD_WITH_DEPOSIT));

        self::assertNotNull($deposit);
        self::assertNull($deposit->iban);
        self::assertNull($deposit->spayd);
    }

    public function testNullForNonDepositMode(): void
    {
        $builder = $this->builder([DepositConfig::KEY_VALUE => '1000']);

        self::assertNull($builder->forReservation($this->reservation(BillingMode::FKSP)));
        self::assertNull($builder->forReservation($this->reservation(BillingMode::AIRBNB)));
    }

    public function testNullForForeignCurrency(): void
    {
        $builder = $this->builder([DepositConfig::KEY_VALUE => '1000']);
        $reservation = $this->reservation(BillingMode::STANDARD_WITH_DEPOSIT);
        $reservation->setPriceCurrency('EUR');

        self::assertNull($builder->forReservation($reservation));
    }

    private function reservation(BillingMode $mode): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-07-20'));
        $r->setBillingMode($mode);
        $r->setPriceTotal('4000');
        $r->setExternalId('1760');

        return $r;
    }

    /** @param array<string, string> $stored */
    private function builder(array $stored): DepositPaymentBuilder
    {
        $settings = $this->createMock(SettingRepository::class);
        $settings->method('getString')->willReturnCallback(
            static fn (string $key, ?string $default = null): ?string => $stored[$key] ?? $default,
        );
        $settings->method('getInt')->willReturnCallback(
            static fn (string $key, int $default = 0): int => isset($stored[$key]) ? (int) $stored[$key] : $default,
        );

        return new DepositPaymentBuilder(
            new DepositConfig($settings),
            new IssuerProfileProvider($settings),
            new SpaydGenerator(),
        );
    }
}
