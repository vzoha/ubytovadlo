<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Cashflow;

use App\Cashflow\AccountBalanceCalculator;
use App\Entity\Account;
use App\Entity\BalanceStatement;
use App\Entity\LedgerEntry;
use App\Entity\Payment;
use App\Entity\Reservation;
use App\Entity\ReservationIncome;
use App\Enum\AccountType;
use App\Enum\Channel;
use App\Enum\IncomeSource;
use App\Enum\LedgerEntryType;
use App\Enum\PaymentSource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccountBalanceCalculatorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private AccountBalanceCalculator $calculator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->calculator = $container->get(AccountBalanceCalculator::class);

        // Reservation nemažeme — reziduální faktury/úklidy jiných testů drží FK;
        // pro výpočet zůstatku stačí čistá cashflow data a platby.
        foreach ([ReservationIncome::class, LedgerEntry::class, BalanceStatement::class, Payment::class, Account::class] as $class) {
            $this->em->createQuery('DELETE FROM ' . $class . ' e')->execute();
        }
    }

    public function testEmptyAccountEqualsOpeningBalance(): void
    {
        $bank = $this->persistAccount('Účet', AccountType::BANK, 1000);
        $this->em->flush();

        self::assertSame(1000, $this->calculator->balance($bank));
    }

    public function testExpensesTransfersIncomeAndAdjustments(): void
    {
        $bank = $this->persistAccount('Banka', AccountType::BANK, 1000);
        $cash = $this->persistAccount('Hotovost', AccountType::CASH, 500);

        // Výdaj −300 z banky.
        $this->persistEntry(LedgerEntryType::EXPENSE, 300, $bank);
        // Převod 200 hotovost → banka.
        $transfer = new LedgerEntry(LedgerEntryType::TRANSFER, new \DateTimeImmutable('2026-04-01'), 200, $cash);
        $transfer->setCounterAccount($bank);
        $this->em->persist($transfer);
        // Korekce +50 na bance.
        $this->persistEntry(LedgerEntryType::ADJUSTMENT, 50, $bank);

        // Příjem rezervace 700 na banku.
        $reservation = $this->persistReservation();
        $income = new ReservationIncome($reservation, '700.00', IncomeSource::PAID_INVOICE);
        $income->setAccount($bank);
        $income->setReceivedOn(new \DateTimeImmutable('2026-03-15'));
        $this->em->persist($income);

        $this->em->flush();

        // Banka: 1000 − 300 + 200 (převod dovnitř) + 50 + 700 = 1650
        self::assertSame(1650, $this->calculator->balance($bank));
        // Hotovost: 500 − 200 (převod ven) = 300
        self::assertSame(300, $this->calculator->balance($cash));
    }

    public function testFutureIncomeNotInCurrentBalance(): void
    {
        // Aktuální stav (upTo = dnes) nepočítá příjem s datem přijetí v budoucnu
        // (např. OTA odhad u budoucího pobytu); minulý příjem se počítá.
        $bank = $this->persistAccount('Banka', AccountType::BANK, 1000);
        $reservation = $this->persistReservation();
        $future = new ReservationIncome($reservation, '5000.00', IncomeSource::ESTIMATE);
        $future->setAccount($bank);
        $future->setReceivedOn(new \DateTimeImmutable('2100-01-01'));
        $this->em->persist($future);
        $this->em->flush();

        $today = new \DateTimeImmutable('today');
        self::assertSame(1000, $this->calculator->balance($bank, $today));
    }

    public function testUnassignedPaymentCountsOnDefaultBank(): void
    {
        $bank = $this->persistAccount('Banka', AccountType::BANK, 0);
        $payment = new Payment(PaymentSource::CS_EMAIL, '1234.00', 'CZK', new \DateTimeImmutable('2026-02-01'), '<x@e>');
        $this->em->persist($payment);
        $this->em->flush();

        self::assertSame(1234, $this->calculator->balance($bank));
    }

    public function testMovementsBeforeOpeningDateAreIgnored(): void
    {
        // Počáteční stav je zůstatek k openingDate → starší pohyby už jsou v něm.
        $bank = new Account('Banka', AccountType::BANK, 42693, new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($bank);
        $this->persistEntry(LedgerEntryType::EXPENSE, 5000, $bank, '2025-11-10'); // před kotvou → ignorovat
        $this->persistEntry(LedgerEntryType::EXPENSE, 60000, $bank, '2026-04-22'); // po kotvě → počítat
        $this->em->flush();

        self::assertSame(42693 - 60000, $this->calculator->balance($bank));
    }

    public function testBalanceRespectsUpToDate(): void
    {
        $bank = $this->persistAccount('Banka', AccountType::BANK, 0);
        $this->persistEntry(LedgerEntryType::EXPENSE, 100, $bank, '2026-01-10');
        $this->persistEntry(LedgerEntryType::EXPENSE, 100, $bank, '2026-06-10');
        $this->em->flush();

        self::assertSame(-100, $this->calculator->balance($bank, new \DateTimeImmutable('2026-03-01')));
        self::assertSame(-200, $this->calculator->balance($bank));
    }

    private function persistAccount(string $name, AccountType $type, int $opening): Account
    {
        $account = new Account($name, $type, $opening, new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($account);

        return $account;
    }

    private function persistEntry(LedgerEntryType $type, int $amount, Account $account, string $date = '2026-04-01'): void
    {
        $this->em->persist(new LedgerEntry($type, new \DateTimeImmutable($date), $amount, $account));
    }

    private function persistReservation(): Reservation
    {
        $r = new Reservation(Channel::WEB, new \DateTimeImmutable('2026-03-10'));
        $r->setCheckOut(new \DateTimeImmutable('2026-03-12'));
        $r->setGuestName('Test Host');
        $this->em->persist($r);

        return $r;
    }
}
