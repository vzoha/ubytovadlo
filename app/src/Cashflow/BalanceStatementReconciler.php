<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Cashflow;

use App\Entity\BalanceStatement;
use App\Entity\LedgerEntry;
use App\Enum\LedgerEntryType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Porovná uzávěrku (reálný stav) s očekávaným stavem k datu a nabídne srovnání
 * rozdílu korekcí (LedgerEntry typu ADJUSTMENT), aby další očekávaný stav seděl.
 * Korekce pokrývá dnešní CSV řádky „Nezapsané příjmy", „Hotovost", „Chybí vybrat".
 */
final class BalanceStatementReconciler
{
    public function __construct(
        private readonly AccountBalanceCalculator $balances,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{expected: int, actual: int, difference: int}
     */
    public function reconcile(BalanceStatement $statement): array
    {
        $expected = $this->balances->balance($statement->getAccount(), $statement->getStatementDate());
        $actual = $statement->getActualBalanceCzk();

        return [
            'expected' => $expected,
            'actual' => $actual,
            'difference' => $actual - $expected,
        ];
    }

    /**
     * Založí korekci na rozdíl mezi reálným a očekávaným stavem. Vrací null,
     * když je rozdíl nulový (není co srovnávat).
     */
    public function createCorrection(BalanceStatement $statement): ?LedgerEntry
    {
        $difference = $this->reconcile($statement)['difference'];
        if ($difference === 0) {
            return null;
        }

        $entry = new LedgerEntry(
            LedgerEntryType::ADJUSTMENT,
            $statement->getStatementDate(),
            $difference,
            $statement->getAccount(),
        );
        $entry->setNote(sprintf('Korekce dle uzávěrky k %s', $statement->getStatementDate()->format('j. n. Y')));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }
}
