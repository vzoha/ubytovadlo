<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ElectricityReading;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ElectricityReading>
 */
class ElectricityReadingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ElectricityReading::class);
    }

    /** @return list<ElectricityReading> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.readAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOnDate(\DateTimeImmutable $date): ?ElectricityReading
    {
        return $this->findOneBy(['readAt' => $date]);
    }

    public function findPrevious(\DateTimeImmutable $date): ?ElectricityReading
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.readAt < :d')
            ->setParameter('d', $date)
            ->orderBy('r.readAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findNext(\DateTimeImmutable $date): ?ElectricityReading
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.readAt > :d')
            ->setParameter('d', $date)
            ->orderBy('r.readAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
