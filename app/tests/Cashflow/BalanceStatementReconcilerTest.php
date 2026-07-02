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
use App\Cashflow\BalanceStatementReconciler;
use App\Entity\Account;
use App\Entity\BalanceStatement;
use App\Entity\LedgerEntry;
use App\Enum\AccountType;
use App\Enum\LedgerEntryType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BalanceStatementReconcilerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private BalanceStatementReconciler $reconciler;
    private AccountBalanceCalculator $balances;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $this->em = $em;
        $this->reconciler = $container->get(BalanceStatementReconciler::class);
        $this->balances = $container->get(AccountBalanceCalculator::class);

        foreach ([LedgerEntry::class, BalanceStatement::class, Account::class] as $class) {
            $this->em->createQuery('DELETE FROM ' . $class . ' e')->execute();
        }
    }

    public function testReconcileReportsDifference(): void
    {
        $bank = $this->persistBank(1000);
        $this->em->persist(new LedgerEntry(LedgerEntryType::EXPENSE, new \DateTimeImmutable('2026-02-01'), 300, $bank));
        $statement = new BalanceStatement($bank, new \DateTimeImmutable('2026-03-01'), 900);
        $this->em->persist($statement);
        $this->em->flush();

        $result = $this->reconciler->reconcile($statement);

        self::assertSame(700, $result['expected']);
        self::assertSame(900, $result['actual']);
        self::assertSame(200, $result['difference']);
    }

    public function testCreateCorrectionAlignsBalance(): void
    {
        $bank = $this->persistBank(1000);
        $this->em->persist(new LedgerEntry(LedgerEntryType::EXPENSE, new \DateTimeImmutable('2026-02-01'), 300, $bank));
        $statement = new BalanceStatement($bank, new \DateTimeImmutable('2026-03-01'), 900);
        $this->em->persist($statement);
        $this->em->flush();

        $correction = $this->reconciler->createCorrection($statement);

        self::assertNotNull($correction);
        self::assertSame(LedgerEntryType::ADJUSTMENT, $correction->getType());
        self::assertSame(200, $correction->getAmountCzk());
        // Po korekci sedí očekávaný stav k datu uzávěrky na reálný.
        self::assertSame(900, $this->balances->balance($bank, new \DateTimeImmutable('2026-03-01')));
        self::assertSame(0, $this->reconciler->reconcile($statement)['difference']);
    }

    public function testNoCorrectionWhenBalanceMatches(): void
    {
        $bank = $this->persistBank(1000);
        $statement = new BalanceStatement($bank, new \DateTimeImmutable('2026-03-01'), 1000);
        $this->em->persist($statement);
        $this->em->flush();

        self::assertNull($this->reconciler->createCorrection($statement));
    }

    private function persistBank(int $opening): Account
    {
        $bank = new Account('Banka', AccountType::BANK, $opening, new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($bank);

        return $bank;
    }
}
