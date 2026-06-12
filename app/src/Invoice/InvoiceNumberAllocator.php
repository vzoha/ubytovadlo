<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Invoice;

use App\Repository\InvoiceRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Alokátor čísel faktur ve formátu RRRR### (např. 2026012).
 *
 * Pravidla:
 *  - Pořadové číslo se resetuje každý rok.
 *  - V daném roce může být start ofsetnut přes seriesStarts (např. v 2026 začínáme od 12,
 *    protože 2026001-2026011 byly vystaveny historicky v původní fakturaci).
 *  - Použita SERIALIZABLE transakce + SELECT FOR UPDATE, aby paralelní vystavení
 *    nevyrobilo kolizní čísla. Sdílený hosting cron běh = málokdy paralelně, ale
 *    jediný UNIQUE constraint v DB je poslední pojistka.
 */
class InvoiceNumberAllocator
{
    /**
     * @param array<int, int> $seriesStarts mapuje rok → minimální pořadové číslo. Např. [2026 => 12] znamená,
     *                                      že první alokované číslo v 2026 bude 2026012.
     */
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvoiceRepository $invoices,
        private readonly array $seriesStarts = [],
    ) {
    }

    public function allocate(\DateTimeImmutable $issuedAt): InvoiceNumber
    {
        $year = (int) $issuedAt->format('Y');
        $connection = $this->em->getConnection();

        return $connection->transactional(function (Connection $conn) use ($year): InvoiceNumber {
            $conn->executeQuery('SELECT MAX(series_year) FROM invoice WHERE series_year = ? FOR UPDATE', [$year]);

            $highest = $this->invoices->findHighestSequenceInYear($year);
            $minStart = $this->seriesStarts[$year] ?? 1;
            $nextSeq = max($highest + 1, $minStart);

            return new InvoiceNumber($year, $nextSeq);
        });
    }
}
