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
use App\Entity\ReservationReceipt;
use App\Enum\AccountType;
use App\Enum\Channel;
use App\Enum\IncomeSource;
use App\Enum\InvoiceType;
use App\Enum\ReceiptOrigin;
use App\Enum\ReservationStatus;
use App\Repository\ReservationReceiptRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IncomeUpserterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private IncomeUpserter $upserter;
    private ReservationReceiptRepository $receipts;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->upserter = $container->get(IncomeUpserter::class);
        $this->receipts = $container->get(ReservationReceiptRepository::class);

        foreach ([ReservationReceipt::class, Payment::class, Cleaning::class, InvoiceLine::class, Invoice::class, Reservation::class, Account::class] as $class) {
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

        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(1, $receipts);
        self::assertSame(IncomeSource::PAID_INVOICE, $receipts[0]->getSource());
        self::assertSame(ReceiptOrigin::INVOICE, $receipts[0]->getOriginType());
        self::assertSame('4455.00', $receipts[0]->getAmountCzk());
        self::assertSame('Banka', $receipts[0]->getAccount()?->getName());
    }

    public function testWebDepositAndFinalAreSeparateReceiptsWithOwnDates(): void
    {
        $r = $this->persistReservation(Channel::WEB);
        // Záloha přijatá dřív (leden), doplatek při příjezdu (březen).
        $this->persistInvoice($r, InvoiceType::DEPOSIT, '1000.00', paid: true, paidAt: '2026-01-18');
        $this->persistInvoice($r, InvoiceType::FINAL, '4000.00', paid: true, paidAt: '2026-03-13');
        $this->em->flush();

        $this->upserter->recompute($r);

        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(2, $receipts);
        self::assertSame('5000.00', $this->sumFor($r));
        // Každá platba nese vlastní měsíc přijetí — klíč pro měsíční cashflow.
        $months = array_map(static fn (ReservationReceipt $x): string => (string) $x->getReceivedOn()?->format('Y-m'), $receipts);
        sort($months);
        self::assertSame(['2026-01', '2026-03'], $months);
    }

    public function testRecordManualPaymentCreatesProtectedReceipt(): void
    {
        $r = $this->persistReservation(Channel::DIRECT, '5000.00');
        $this->em->flush();

        $this->upserter->recordManualPayment($r, '2000.00', new \DateTimeImmutable('2026-03-05'));

        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(1, $receipts);
        self::assertSame(IncomeSource::MANUAL_PAYMENT, $receipts[0]->getSource());
        self::assertSame(ReceiptOrigin::MANUAL_PAYMENT, $receipts[0]->getOriginType());
        self::assertTrue($receipts[0]->isManuallyOverridden());
        self::assertSame('2000.00', $receipts[0]->getAmountCzk());
    }

    public function testManualPaymentsAccumulateAndSurviveRecompute(): void
    {
        $r = $this->persistReservation(Channel::DIRECT, '5000.00');
        $this->em->flush();

        $this->upserter->recordManualPayment($r, '1000.00', new \DateTimeImmutable('2026-03-01'));
        $this->upserter->recordManualPayment($r, '1500.00', new \DateTimeImmutable('2026-03-05'));
        // Přepočet nesmí ruční platby smazat (jsou chráněné).
        $this->upserter->recompute($r);

        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(2, $receipts);
        self::assertSame('2500.00', $this->sumFor($r));
        // Distinktní pořadová čísla → obě platby přežijí (unique origin key).
        $ids = array_map(static fn (ReservationReceipt $x): int => $x->getOriginId(), $receipts);
        sort($ids);
        self::assertSame([1, 2], $ids);
    }

    public function testWebUnpaidInvoiceHasNoIncomeYet(): void
    {
        $r = $this->persistReservation(Channel::WEB, price: '3000.00');
        $this->persistInvoice($r, InvoiceType::FULL, '3000.00', paid: false);
        $this->em->flush();

        $this->upserter->recompute($r);

        // Přímá objednávka: dokud host nezaplatí, na účtu nic není.
        self::assertCount(0, $this->receipts->findForReservation($r));
    }

    public function testUnpaidFinalKeepsPaidDepositReceipt(): void
    {
        $r = $this->persistReservation(Channel::WEB);
        $this->persistInvoice($r, InvoiceType::DEPOSIT, '1000.00', paid: true, paidAt: '2026-01-18');
        $this->persistInvoice($r, InvoiceType::FINAL, '4000.00', paid: false);
        $this->em->flush();

        $this->upserter->recompute($r);

        // Zaplacená je jen záloha → na účtu je zatím 1000, ne celá cena.
        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(1, $receipts);
        self::assertSame('1000.00', $receipts[0]->getAmountCzk());
    }

    public function testWebCashInvoiceGoesToCashAccount(): void
    {
        $r = $this->persistReservation(Channel::WEB);
        $invoice = $this->persistInvoice($r, InvoiceType::FULL, '2000.00', paid: true);
        $invoice->setPaymentMethod('hotově');
        $this->em->flush();

        $this->upserter->recompute($r);

        self::assertSame('Hotovost', $this->receipts->findForReservation($r)[0]->getAccount()?->getName());
    }

    public function testWebManualOverrideIsNotAutoUpdated(): void
    {
        $r = $this->persistReservation(Channel::WEB);
        $this->persistInvoice($r, InvoiceType::FULL, '4000.00', paid: true);
        $this->em->flush();
        $this->upserter->recompute($r);

        $receipt = $this->receipts->findForReservation($r)[0];
        $receipt->setAmountCzk('9999.00')->setManuallyOverridden(true);
        $this->em->flush();

        $this->upserter->recompute($r);

        self::assertSame('9999.00', $this->receipts->findForReservation($r)[0]->getAmountCzk());
    }

    // --- OTA: odhad net (hrubá − provize), zpřesněný reálnou výplatou ---

    public function testOtaIncomeIsEstimateNetOfCommission(): void
    {
        $r = $this->persistReservation(Channel::AIRBNB, price: '5000.00');
        $r->setCommissionAmount('150.00')->setCommissionCurrency('CZK');
        $this->em->flush();

        $this->upserter->recompute($r);

        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(1, $receipts);
        self::assertSame(IncomeSource::ESTIMATE, $receipts[0]->getSource());
        self::assertSame(ReceiptOrigin::ESTIMATE, $receipts[0]->getOriginType());
        self::assertFalse($receipts[0]->getSource()->isRealized());
        // net = 5000 − 150 provize
        self::assertSame('4850.00', $receipts[0]->getAmountCzk());
    }

    public function testAirbnbPayoutReplacesEstimate(): void
    {
        $r = $this->persistReservation(Channel::AIRBNB, price: '5000.00');
        $r->setCommissionAmount('150.00')->setCommissionCurrency('CZK');
        $this->em->flush();
        $this->upserter->recompute($r);
        self::assertSame(IncomeSource::ESTIMATE, $this->receipts->findForReservation($r)[0]->getSource());

        // Dorazí reálná výplata (net) → nahradí odhad (žádná duplicita).
        $r->setPayoutAmount('4820.00');
        $r->setPayoutSentAt(new \DateTimeImmutable('2026-04-20'));
        $this->em->flush();
        $this->upserter->recompute($r);

        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(1, $receipts);
        self::assertSame(IncomeSource::OTA_PAYOUT, $receipts[0]->getSource());
        self::assertTrue($receipts[0]->getSource()->isRealized());
        self::assertSame('4820.00', $receipts[0]->getAmountCzk());
    }

    public function testManualPayoutReplacesEstimateAndLocks(): void
    {
        $r = $this->persistReservation(Channel::BOOKING, price: '6000.00');
        $r->setCommissionAmount('900.00')->setCommissionCurrency('CZK');
        $this->em->flush();
        $this->upserter->recompute($r);
        self::assertSame('5100.00', $this->receipts->findForReservation($r)[0]->getAmountCzk());

        // Ruční záznam reálné výplaty → nahradí odhad a zamkne.
        $this->upserter->recordManualPayout($r, '5080.00', new \DateTimeImmutable('2026-05-15'));
        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(1, $receipts);
        self::assertSame(IncomeSource::OTA_PAYOUT, $receipts[0]->getSource());
        self::assertSame(ReceiptOrigin::MANUAL, $receipts[0]->getOriginType());
        self::assertSame('5080.00', $receipts[0]->getAmountCzk());
        self::assertTrue($receipts[0]->isManuallyOverridden());

        // Auto-přepočet už ručně zadanou výplatu nemění.
        $this->upserter->recompute($r);
        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(1, $receipts);
        self::assertSame('5080.00', $receipts[0]->getAmountCzk());
    }

    public function testManualPayoutReplacesAllAutoReceipts(): void
    {
        // I kdyby rezervace měla auto receipt z faktury (INVOICE), ruční výplata
        // je nahradí jediným MANUAL řádkem — žádný duplikát, stav účtu se nezdvojí.
        $r = $this->persistReservation(Channel::BOOKING, price: '6000.00');
        $this->persistInvoice($r, InvoiceType::FULL, '6000.00', paid: true);
        $this->em->flush();
        $this->upserter->recompute($r);

        $this->upserter->recordManualPayout($r, '5800.00', new \DateTimeImmutable('2026-05-15'));

        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(1, $receipts);
        self::assertSame(ReceiptOrigin::MANUAL, $receipts[0]->getOriginType());
        self::assertSame('5800.00', $receipts[0]->getAmountCzk());
    }

    public function testRecomputeIsIdempotent(): void
    {
        $r = $this->persistReservation(Channel::WEB);
        $this->persistInvoice($r, InvoiceType::DEPOSIT, '1000.00', paid: true, paidAt: '2026-01-18');
        $this->persistInvoice($r, InvoiceType::FINAL, '4000.00', paid: true, paidAt: '2026-03-13');
        $this->em->flush();

        $this->upserter->recompute($r);
        $this->upserter->recompute($r);
        $this->upserter->recompute($r);

        // Opakovaný přepočet neduplikuje řádky.
        self::assertCount(2, $this->receipts->findForReservation($r));
    }

    public function testCancelledReservationWithPaidInvoiceKeepsIncome(): void
    {
        // Zrušená rezervace se zaplacenou fakturou = nevrácená záloha / storno-poplatek
        // → zůstává příjmem (peníze reálně přišly a nevrátily se).
        $r = $this->persistReservation(Channel::WEB, price: '3000.00');
        $this->persistInvoice($r, InvoiceType::DEPOSIT, '1000.00', paid: true, paidAt: '2026-03-31');
        $this->em->flush();
        $this->upserter->recompute($r);
        self::assertCount(1, $this->receipts->findForReservation($r));

        $r->setStatus(ReservationStatus::CANCELLED);
        $this->em->flush();
        $this->upserter->recompute($r);

        $receipts = $this->receipts->findForReservation($r);
        self::assertCount(1, $receipts);
        self::assertSame('1000.00', $receipts[0]->getAmountCzk());
        self::assertSame('2026-03-31', $receipts[0]->getReceivedOn()?->format('Y-m-d'));
    }

    public function testCancelledOtaWithoutRealPayoutHasNoIncome(): void
    {
        // Zrušený OTA pobyt bez reálné výplaty = jen odhad → u storna se nevede.
        $r = $this->persistReservation(Channel::AIRBNB, price: '5000.00');
        $r->setCommissionAmount('150.00')->setCommissionCurrency('CZK');
        $r->setStatus(ReservationStatus::CANCELLED);
        $this->em->flush();

        $this->upserter->recompute($r);

        self::assertCount(0, $this->receipts->findForReservation($r));
    }

    private function sumFor(Reservation $r): string
    {
        $sum = '0.00';
        foreach ($this->receipts->findForReservation($r) as $receipt) {
            $sum = bcadd($sum, $receipt->getAmountCzk(), 2);
        }

        return $sum;
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
        $r->setStatus(ReservationStatus::CONFIRMED);
        $r->setGuestName('Test Host');
        $r->setGuestsAdult(2);
        if ($price !== null) {
            $r->setPriceTotal($price);
            $r->setPriceCurrency('CZK');
        }
        $this->em->persist($r);

        return $r;
    }

    private function persistInvoice(Reservation $r, InvoiceType $type, string $total, bool $paid, ?string $paidAt = null): Invoice
    {
        static $seq = 0;
        $n = ++$seq + 800;
        $invoice = new Invoice(
            sprintf('2026%03d', $n),
            2026,
            $n,
            $type,
            $r,
            new \DateTimeImmutable('2026-01-15'),
            new \DateTimeImmutable('2026-01-29'),
        );
        $invoice->setTotalAmount($total);
        if ($paid) {
            $invoice->setPaidAt(new \DateTimeImmutable($paidAt ?? '2026-03-13'));
        }
        $this->em->persist($invoice);

        return $invoice;
    }
}
