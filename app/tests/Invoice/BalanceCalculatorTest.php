<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Invoice;

use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Enum\BillingMode;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Invoice\BalanceCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BalanceCalculatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BalanceCalculator $calc;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->calc = $container->get(BalanceCalculator::class);

        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
    }

    public function testRemainingAfterPaidDeposit(): void
    {
        $r = $this->reservation('5000.00', 'CZK');
        $this->paidInvoice($r, InvoiceType::DEPOSIT, '1000.00');
        $this->em->flush();

        $balance = $this->calc->forReservation($r);

        self::assertNotNull($balance);
        self::assertSame(5000.0, $balance->total);
        self::assertSame(1000.0, $balance->paid);
        self::assertSame(4000.0, $balance->remaining);
        self::assertFalse($balance->isSettled());
    }

    public function testSettledWhenAllPaid(): void
    {
        $r = $this->reservation('3000.00', 'CZK');
        $this->paidInvoice($r, InvoiceType::FULL, '3000.00');
        $this->em->flush();

        $balance = $this->calc->forReservation($r);

        self::assertNotNull($balance);
        self::assertTrue($balance->isSettled());
        self::assertSame(0.0, $balance->remaining);
    }

    public function testNullForEurPrice(): void
    {
        $r = $this->reservation('200.00', 'EUR');
        $this->em->flush();

        self::assertNull($this->calc->forReservation($r));
    }

    public function testNullForWaived(): void
    {
        $r = $this->reservation('3000.00', 'CZK');
        $r->setBillingMode(BillingMode::WAIVED);
        $this->em->flush();

        self::assertNull($this->calc->forReservation($r));
    }

    private function reservation(string $price, string $currency): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-07-01'));
        $r->setCheckOut(new \DateTimeImmutable('2026-07-03'));
        $r->setPriceTotal($price)->setPriceCurrency($currency);
        $r->setGuestName('Test Host');
        $this->em->persist($r);

        return $r;
    }

    private function paidInvoice(Reservation $r, InvoiceType $type, string $amount): void
    {
        $inv = new Invoice('2026' . random_int(100, 999), 2026, $type, $r, new \DateTimeImmutable(), new \DateTimeImmutable('+14 days'));
        $inv->setTotalAmount($amount)->setCurrency('CZK')->setPaidAt(new \DateTimeImmutable());
        $this->em->persist($inv);
    }
}
