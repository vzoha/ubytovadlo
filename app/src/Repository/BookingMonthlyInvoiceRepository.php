<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\BookingMonthlyInvoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BookingMonthlyInvoice>
 */
class BookingMonthlyInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BookingMonthlyInvoice::class);
    }

    public function findByInvoiceNumber(string $invoiceNumber): ?BookingMonthlyInvoice
    {
        return $this->findOneBy(['invoiceNumber' => $invoiceNumber]);
    }

    /**
     * Najde fakturu, jejíž period_to spadá do daného měsíce. Pro reconcile
     * (sečíst rezervace s odjezdem v měsíci, porovnat s touto fakturou).
     */
    public function findByPeriodMonth(int $year, int $month): ?BookingMonthlyInvoice
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to = $from->modify('last day of this month');

        return $this->createQueryBuilder('i')
            ->andWhere('i.periodTo BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
