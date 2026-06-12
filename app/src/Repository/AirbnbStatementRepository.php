<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AirbnbStatement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AirbnbStatement>
 */
class AirbnbStatementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AirbnbStatement::class);
    }

    /**
     * Airbnb posílá samostatný earnings receipt za každou rezervaci, ne souhrnný
     * měsíční. Pro DPH se v daném měsíci může pojmout víc statementů.
     *
     * @return list<AirbnbStatement>
     */
    public function findAllByPeriodMonth(int $year, int $month): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to = $from->modify('last day of this month');

        /** @var list<AirbnbStatement> $rows */
        $rows = $this->createQueryBuilder('s')
            ->andWhere('s.periodTo BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('s.periodFrom', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
