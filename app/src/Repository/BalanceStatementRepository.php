<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Account;
use App\Entity\BalanceStatement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BalanceStatement>
 */
class BalanceStatementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BalanceStatement::class);
    }

    /**
     * @return BalanceStatement[]
     */
    public function findForAccount(Account $account): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.account = :a')
            ->setParameter('a', $account)
            ->orderBy('s.statementDate', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findLatestForAccount(Account $account): ?BalanceStatement
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.account = :a')
            ->setParameter('a', $account)
            ->orderBy('s.statementDate', 'DESC')
            ->addOrderBy('s.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
