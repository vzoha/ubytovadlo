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
use App\Enum\AccountType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /**
     * @return Account[]
     */
    public function findOrdered(bool $onlyActive = false): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.id', 'ASC');
        if ($onlyActive) {
            $qb->andWhere('a.active = true');
        }

        return $qb->getQuery()->getResult();
    }

    /** Výchozí aktivní účet daného typu (nejnižší sortOrder) — kam se zařadí automatický příjem. */
    public function findDefaultByType(AccountType $type): ?Account
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.type = :type')
            ->andWhere('a.active = true')
            ->setParameter('type', $type)
            ->orderBy('a.sortOrder', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
