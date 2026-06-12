<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Profit;

use App\Entity\Cleaning;
use App\Entity\ElectricityTariff;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Reservation;
use App\Enum\Channel;
use App\Enum\InvoiceType;
use App\Enum\ReservationStatus;
use App\Profit\ReservationProfitCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ReservationProfitCalculatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ReservationProfitCalculator $calculator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->calculator = $container->get(ReservationProfitCalculator::class);

        $this->em->createQuery('DELETE FROM ' . Cleaning::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . InvoiceLine::class . ' l')->execute();
        $this->em->createQuery('DELETE FROM ' . Invoice::class . ' i')->execute();
        $this->em->createQuery('DELETE FROM ' . Reservation::class . ' r')->execute();
        $this->em->createQuery('DELETE FROM ' . ElectricityTariff::class . ' t')->execute();
    }

    public function testWebReservationWithFullInvoice(): void
    {
        // Zrcadlí CSV výpočet: výdaje = elektřina + úklid + rekreační + provize + DPH.
        $r = $this->makeReservation(Channel::WEB, '2026-03-20', '2026-03-22', adults: 2);
        $r->setPriceTotal('4455.00')->setPriceCurrency('CZK');
        $r->setVtKwh(146)->setNtKwh(86);
        $this->em->persist(new ElectricityTariff(new \DateTimeImmutable('2026-01-01'), '5.75', '3.80'));
        // Cleaning (Barča, 400 Kč pro 2 hosty) auto-vytváří ReservationCleaningListener
        $this->makeInvoice($r, InvoiceType::FULL, '4455.00');
        $this->em->flush();

        $p = $this->calculator->calculate($r);

        self::assertSame(2, $p->nights);
        self::assertSame('4455.00', $p->incomeCzk);
        self::assertFalse($p->incomeIsEstimate);
        // elektřina 146×5.75 + 86×3.80 = 839.50 + 326.80 = 1166.30
        self::assertSame('1166.30', $p->electricityCzk);
        self::assertSame('400.00', $p->cleaningCzk);
        // rekreační 15 × 2 dospělí × 2 noci = 60
        self::assertSame('60.00', $p->recreationFeeCzk);
        self::assertSame('0.00', $p->commissionCzk);
        self::assertSame('0.00', $p->vatCzk);
        self::assertSame('1626.30', $p->expensesTotalCzk);
        self::assertSame('2828.70', $p->profitCzk);
        self::assertSame('1414.35', $p->profitPerNightCzk);
        self::assertFalse($p->missingIncome);
        self::assertFalse($p->missingElectricity);
        self::assertFalse($p->missingCleaning);
    }

    public function testBookingEurWithoutInvoiceIsEstimated(): void
    {
        $r = $this->makeReservation(Channel::BOOKING, '2026-04-13', '2026-04-16', adults: 2);
        $r->setPriceTotal('100.00')->setPriceCurrency('EUR');
        $r->setVatCnbRate('24.36000000');
        $r->setVatBaseCzk('365.40')->setVatAmountCzk('76.73');
        $this->em->flush();

        $p = $this->calculator->calculate($r);

        self::assertSame('2436.00', $p->incomeCzk);
        self::assertTrue($p->incomeIsEstimate);
        self::assertSame('365.40', $p->commissionCzk);
        self::assertSame('76.73', $p->vatCzk);
        self::assertTrue($p->missingElectricity);
        self::assertFalse($p->missingCleaning); // auto-provisioned Barča 400
        // výdaje = elektřina 0 + úklid 400 + rekreační (15×2×3=90) + 365.40 + 76.73
        self::assertSame('932.13', $p->expensesTotalCzk);
        self::assertSame('1503.87', $p->profitCzk);
    }

    public function testDepositPlusFinalInvoiceIsNotDoubleCounted(): void
    {
        $r = $this->makeReservation(Channel::WEB, '2026-05-01', '2026-05-03', adults: 2);
        $r->setPriceTotal('5000.00')->setPriceCurrency('CZK');
        $deposit = $this->makeInvoice($r, InvoiceType::DEPOSIT, '1000.00');
        $final = $this->makeInvoice($r, InvoiceType::FINAL, '4000.00');
        $final->setParentInvoice($deposit);
        $this->em->flush();

        $p = $this->calculator->calculate($r);

        // konečná (4000) + záloha (1000) = celý příjem, žádné dvojí započtení
        self::assertSame('5000.00', $p->incomeCzk);
        self::assertFalse($p->incomeIsEstimate);
    }

    public function testDepositOnlyFallsBackToPrice(): void
    {
        $r = $this->makeReservation(Channel::WEB, '2026-05-10', '2026-05-12', adults: 2);
        $r->setPriceTotal('5000.00')->setPriceCurrency('CZK');
        $this->makeInvoice($r, InvoiceType::DEPOSIT, '1000.00');
        $this->em->flush();

        $p = $this->calculator->calculate($r);

        // samotná záloha není celý příjem → bere se cena rezervace
        self::assertSame('5000.00', $p->incomeCzk);
        self::assertFalse($p->incomeIsEstimate);
    }

    public function testLegacyEurInvoiceIsConvertedByCnbRate(): void
    {
        // Starší faktura z původní fakturace pro zahraničního hosta — currency EUR,
        // částka v EUR. Příjem se musí přepočítat kurzem, ne brát jako CZK.
        $r = $this->makeReservation(Channel::BOOKING, '2024-08-24', '2024-08-26', adults: 2);
        $r->setPriceTotal('174.24')->setPriceCurrency('EUR');
        $r->setVatCnbRate('25.03000000');
        $invoice = $this->makeInvoice($r, InvoiceType::FULL, '174.24');
        $invoice->setCurrency('EUR');
        $this->em->flush();

        $p = $this->calculator->calculate($r);

        // 174.24 × 25.03 = 4361.23 (CSV uvádí 4 360 — kurz dne platby)
        self::assertSame('4361.22', $p->incomeCzk);
        self::assertTrue($p->incomeIsEstimate);
    }

    public function testRecreationFeeCountsAdultsOnly(): void
    {
        $r = $this->makeReservation(Channel::WEB, '2026-06-01', '2026-06-04', adults: 2);
        $r->setGuestsChild(3);
        $r->setPriceTotal('6000.00')->setPriceCurrency('CZK');
        $this->em->flush();

        $p = $this->calculator->calculate($r);

        // 15 Kč × 2 dospělí × 3 noci, děti osvobozeny
        self::assertSame('90.00', $p->recreationFeeCzk);
    }

    public function testMissingPriceAndRateMeansNoProfit(): void
    {
        $r = $this->makeReservation(Channel::BOOKING, '2026-07-01', '2026-07-03', adults: 2);
        $r->setPriceTotal('100.00')->setPriceCurrency('EUR'); // EUR bez kurzu
        $this->em->flush();

        $p = $this->calculator->calculate($r);

        self::assertNull($p->incomeCzk);
        self::assertTrue($p->missingIncome);
        self::assertNull($p->profitCzk);
        self::assertNull($p->profitPerNightCzk);
        self::assertSame('460.00', $p->expensesTotalCzk); // úklid 400 + rekreační 60
    }

    public function testBatchMatchesSingleCalculation(): void
    {
        $a = $this->makeReservation(Channel::WEB, '2026-08-01', '2026-08-03', adults: 2);
        $a->setPriceTotal('4000.00')->setPriceCurrency('CZK');
        $b = $this->makeReservation(Channel::BOOKING, '2026-08-10', '2026-08-12', adults: 3);
        $b->setPriceTotal('200.00')->setPriceCurrency('EUR');
        $b->setVatCnbRate('25.00000000');
        $this->em->flush();

        $batch = $this->calculator->calculateBatch([$a, $b]);

        self::assertEquals($this->calculator->calculate($a), $batch[$a->getId()]);
        self::assertEquals($this->calculator->calculate($b), $batch[$b->getId()]);
        self::assertSame('400.00', $batch[$a->getId()]->cleaningCzk); // auto Barča pro 2
        self::assertSame('5000.00', $batch[$b->getId()]->incomeCzk);
    }

    private function makeReservation(Channel $channel, string $checkIn, string $checkOut, int $adults): Reservation
    {
        $r = new Reservation($channel, new \DateTimeImmutable($checkIn));
        $r->setCheckOut(new \DateTimeImmutable($checkOut));
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestsAdult($adults);
        $r->setGuestName('Test ' . $checkIn);
        $this->em->persist($r);

        return $r;
    }

    private function makeInvoice(Reservation $r, InvoiceType $type, string $totalAmount): Invoice
    {
        static $seq = 0;
        $invoice = new Invoice(
            sprintf('2026%03d', ++$seq + 900),
            2026,
            $type,
            $r,
            new \DateTimeImmutable('2026-01-15'),
            new \DateTimeImmutable('2026-01-29'),
        );
        $invoice->setTotalAmount($totalAmount);
        $this->em->persist($invoice);

        return $invoice;
    }
}
