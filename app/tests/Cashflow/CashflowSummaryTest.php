<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Cashflow;

use App\Cashflow\CashflowSummary;
use App\Entity\Account;
use App\Entity\LedgerEntry;
use App\Entity\Reservation;
use App\Entity\ReservationReceipt;
use App\Enum\AccountType;
use App\Enum\Channel;
use App\Enum\ExpenseCategory;
use App\Enum\IncomeSource;
use App\Enum\LedgerEntryType;
use App\Enum\ReceiptOrigin;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CashflowSummaryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CashflowSummary $summary;
    private Account $bank;
    private Reservation $reservation;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->summary = $container->get(CashflowSummary::class);

        foreach ([ReservationReceipt::class, LedgerEntry::class, Account::class] as $class) {
            $this->em->createQuery('DELETE FROM ' . $class . ' e')->execute();
        }
        $this->bank = new Account('Banka', AccountType::BANK, 0, new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($this->bank);
        $this->reservation = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-03-10'));
        $this->reservation->setCheckOut(new \DateTimeImmutable('2026-03-12'));
        $this->reservation->setGuestName('Test Host');
        $this->em->persist($this->reservation);
        $this->em->flush();
    }

    public function testDepositAndFinalLandInTheirOwnMonths(): void
    {
        // Záloha v lednu, doplatek v březnu — dvě dílčí platby.
        $this->persistReceipt('1000.00', '2026-01-18', 1);
        $this->persistReceipt('4000.00', '2026-03-13', 2);
        $this->em->flush();

        $result = $this->summary->forYear(2026);

        self::assertSame(1000, $result['months'][0]['income']); // leden
        self::assertSame(4000, $result['months'][2]['income']); // březen
        self::assertSame(5000, $result['totals']['income']);
    }

    public function testExpensesSplitOperatingVsPersonal(): void
    {
        $this->persistExpense(2000, ExpenseCategory::MAINTENANCE, '2026-02-05'); // provozní
        $this->persistExpense(8000, ExpenseCategory::OWNER_WITHDRAWAL, '2026-02-20'); // osobní odliv
        $this->em->flush();

        $result = $this->summary->forYear(2026);

        self::assertSame(2000, $result['months'][1]['operating']);
        self::assertSame(8000, $result['months'][1]['nonOperating']);
        self::assertSame(-10000, $result['months'][1]['net']);
    }

    public function testTransfersAndAdjustmentsAreExcluded(): void
    {
        $other = new Account('Hotovost', AccountType::CASH, 0, new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($other);
        $transfer = new LedgerEntry(LedgerEntryType::TRANSFER, new \DateTimeImmutable('2026-04-01'), 500, $this->bank);
        $transfer->setCounterAccount($other);
        $this->em->persist($transfer);
        $this->em->persist(new LedgerEntry(LedgerEntryType::ADJUSTMENT, new \DateTimeImmutable('2026-04-02'), 300, $this->bank));
        $this->em->flush();

        $result = $this->summary->forYear(2026);

        // Interní přesun ani korekce nejsou příjem/náklad.
        self::assertSame(0, $result['totals']['income']);
        self::assertSame(0, $result['totals']['operating']);
        self::assertSame(0, $result['totals']['nonOperating']);
    }

    public function testOtherIncomeCountsInMonthlyIncome(): void
    {
        // Nerezervační příjem (úroky) se počítá do příjmu měsíce.
        $entry = new LedgerEntry(LedgerEntryType::INCOME, new \DateTimeImmutable('2026-05-10'), 800, $this->bank);
        $this->em->persist($entry);
        $this->em->flush();

        $result = $this->summary->forYear(2026);

        self::assertSame(800, $result['months'][4]['income']); // květen
        self::assertSame(800, $result['totals']['income']);
    }

    private function persistReceipt(string $amount, string $receivedOn, int $originId): void
    {
        $receipt = new ReservationReceipt($this->reservation, $amount, IncomeSource::PAID_INVOICE, ReceiptOrigin::INVOICE, $originId);
        $receipt->setAccount($this->bank);
        $receipt->setReceivedOn(new \DateTimeImmutable($receivedOn));
        $this->em->persist($receipt);
    }

    private function persistExpense(int $amount, ExpenseCategory $category, string $date): void
    {
        $entry = new LedgerEntry(LedgerEntryType::EXPENSE, new \DateTimeImmutable($date), $amount, $this->bank);
        $entry->setCategory($category);
        $this->em->persist($entry);
    }
}
