<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Cashflow;

use App\Cashflow\IncomeUpserter;
use App\Entity\Account;
use App\Entity\Cleaning;
use App\Entity\Invoice;
use App\Entity\InvoiceLine;
use App\Entity\Payment;
use App\Entity\Reservation;
use App\Entity\ReservationIncome;
use App\Enum\AccountType;
use App\Enum\Channel;
use App\Enum\IncomeSource;
use App\Enum\InvoiceType;
use App\Repository\ReservationIncomeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IncomeUpserterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IncomeUpserter $upserter;
    private ReservationIncomeRepository $incomes;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->upserter = $container->get(IncomeUpserter::class);
        $this->incomes = $container->get(ReservationIncomeRepository::class);

        foreach ([ReservationIncome::class, Payment::class, Cleaning::class, InvoiceLine::class, Invoice::class, Reservation::class, Account::class] as $class) {
            $this->em->createQuery('DELETE FROM ' . $class . ' e')->execute();
        }
        $this->persistAccount('Banka', AccountType::BANK);
        $this->persistAccount('Hotovost', AccountType::CASH);
        $this->em->flush();
    }

    // --- Přímá objednávka: reálný příjem = zaplacená faktura ---

    public function testWebPaidInvoiceIsRealIncome(): void
    {
        $r = $this->persistReservation(Channel::WEB);
        $this->persistInvoice($r, InvoiceType::FULL, '4455.00', paid: true);
        $this->em->flush();

        $this->upserter->recompute($r);

        $income = $this->incomes->findForReservation($r);
        self::assertNotNull($income);
        self::assertSame(IncomeSource::PAID_INVOICE, $income->getSource());
        self::assertSame('4455.00', $income->getAmountCzk());
        self::assertSame('Banka', $income->getAccount()?->getName());
    }

    public function testWebDepositPlusFinalSummed(): void
    {
        $r = $this->persistReservation(Channel::WEB);
        $this->persistInvoice($r, InvoiceType::DEPOSIT, '1000.00', paid: true);
        $this->persistInvoice($r, InvoiceType::FINAL, '4000.00', paid: true);
        $this->em->flush();

        $this->upserter->recompute($r);

        self::assertSame('5000.00', $this->incomes->findForReservation($r)?->getAmountCzk());
    }

    public function testWebUnpaidInvoiceHasNoIncomeYet(): void
    {
        $r = $this->persistReservation(Channel::WEB, price: '3000.00');
        $this->persistInvoice($r, InvoiceType::FULL, '3000.00', paid: false);
        $this->em->flush();

        $this->upserter->recompute($r);

        // Přímá objednávka: dokud host nezaplatí, na účtu nic není.
        self::assertNull($this->incomes->findForReservation($r));
    }

    public function testWebCashInvoiceGoesToCashAccount(): void
    {
        $r = $this->persistReservation(Channel::WEB);
        $invoice = $this->persistInvoice($r, InvoiceType::FULL, '2000.00', paid: true);
        $invoice->setPaymentMethod('hotově');
        $this->em->flush();

        $this->upserter->recompute($r);

        self::assertSame('Hotovost', $this->incomes->findForReservation($r)?->getAccount()?->getName());
    }

    public function testWebManualOverrideIsNotAutoUpdated(): void
    {
        $r = $this->persistReservation(Channel::WEB);
        $this->persistInvoice($r, InvoiceType::FULL, '4000.00', paid: true);
        $this->em->flush();
        $this->upserter->recompute($r);

        $income = $this->incomes->findForReservation($r);
        self::assertNotNull($income);
        $income->setAmountCzk('9999.00')->setManuallyOverridden(true);
        $this->em->flush();

        $this->upserter->recompute($r);

        self::assertSame('9999.00', $this->incomes->findForReservation($r)?->getAmountCzk());
    }

    // --- OTA: odhad net (hrubá − provize), zpřesněný reálnou výplatou ---

    public function testOtaIncomeIsEstimateNetOfCommission(): void
    {
        $r = $this->persistReservation(Channel::AIRBNB, price: '5000.00');
        $r->setCommissionAmount('150.00')->setCommissionCurrency('CZK');
        $this->em->flush();

        $this->upserter->recompute($r);

        $income = $this->incomes->findForReservation($r);
        self::assertNotNull($income);
        self::assertSame(IncomeSource::ESTIMATE, $income->getSource());
        self::assertFalse($income->getSource()->isRealized());
        // net = 5000 − 150 provize
        self::assertSame('4850.00', $income->getAmountCzk());
    }

    public function testAirbnbPayoutOverridesEstimate(): void
    {
        $r = $this->persistReservation(Channel::AIRBNB, price: '5000.00');
        $r->setCommissionAmount('150.00')->setCommissionCurrency('CZK');
        $this->em->flush();
        $this->upserter->recompute($r);
        self::assertSame(IncomeSource::ESTIMATE, $this->incomes->findForReservation($r)?->getSource());

        // Dorazí reálná výplata (net) → přepíše odhad.
        $r->setPayoutAmount('4820.00');
        $r->setPayoutSentAt(new \DateTimeImmutable('2026-04-20'));
        $this->em->flush();
        $this->upserter->recompute($r);

        $income = $this->incomes->findForReservation($r);
        self::assertNotNull($income);
        self::assertSame(IncomeSource::OTA_PAYOUT, $income->getSource());
        self::assertTrue($income->getSource()->isRealized());
        self::assertSame('4820.00', $income->getAmountCzk());
    }

    public function testManualPayoutOverridesEstimateAndLocks(): void
    {
        $r = $this->persistReservation(Channel::BOOKING, price: '6000.00');
        $r->setCommissionAmount('900.00')->setCommissionCurrency('CZK');
        $this->em->flush();
        $this->upserter->recompute($r);
        self::assertSame('5100.00', $this->incomes->findForReservation($r)?->getAmountCzk());

        // Ruční záznam reálné výplaty → přepíše odhad a zamkne.
        $this->upserter->recordManualPayout($r, '5080.00', new \DateTimeImmutable('2026-05-15'));
        $income = $this->incomes->findForReservation($r);
        self::assertNotNull($income);
        self::assertSame(IncomeSource::OTA_PAYOUT, $income->getSource());
        self::assertSame('5080.00', $income->getAmountCzk());
        self::assertTrue($income->isManuallyOverridden());

        // Auto-přepočet už ručně zadanou výplatu nemění.
        $this->upserter->recompute($r);
        self::assertSame('5080.00', $this->incomes->findForReservation($r)?->getAmountCzk());
    }

    private function persistAccount(string $name, AccountType $type): Account
    {
        $account = new Account($name, $type, 0, new \DateTimeImmutable('2026-01-01'));
        $account->setSortOrder($type === AccountType::CASH ? 1 : 0);
        $this->em->persist($account);

        return $account;
    }

    private function persistReservation(Channel $channel, ?string $price = null): Reservation
    {
        $r = new Reservation($channel, new \DateTimeImmutable('2026-03-10'));
        $r->setCheckOut(new \DateTimeImmutable('2026-03-12'));
        $r->setGuestName('Test Host');
        $r->setGuestsAdult(2);
        if ($price !== null) {
            $r->setPriceTotal($price);
            $r->setPriceCurrency('CZK');
        }
        $this->em->persist($r);

        return $r;
    }

    private function persistInvoice(Reservation $r, InvoiceType $type, string $total, bool $paid): Invoice
    {
        static $seq = 0;
        $invoice = new Invoice(
            sprintf('2026%03d', ++$seq + 800),
            2026,
            $type,
            $r,
            new \DateTimeImmutable('2026-01-15'),
            new \DateTimeImmutable('2026-01-29'),
        );
        $invoice->setTotalAmount($total);
        if ($paid) {
            $invoice->setPaidAt(new \DateTimeImmutable('2026-03-13'));
        }
        $this->em->persist($invoice);

        return $invoice;
    }
}
