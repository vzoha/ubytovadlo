<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\CustomerDistinctPair;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CustomerDistinctPair>
 */
class CustomerDistinctPairRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CustomerDistinctPair::class);
    }

    public function findPair(Customer $a, Customer $b): ?CustomerDistinctPair
    {
        [$first, $second] = CustomerDistinctPair::ordered($a, $b);

        return $this->findOneBy(['first' => $first, 'second' => $second]);
    }

    /**
     * Dvojice, ve kterých je daný zákazník.
     *
     * @return CustomerDistinctPair[]
     */
    public function findInvolving(Customer $customer): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.first = :customer OR p.second = :customer')
            ->setParameter('customer', $customer)
            ->getQuery()
            ->getResult();
    }

    /**
     * Klíče "menší-id:větší-id" všech odmítnutých dvojic.
     *
     * @return array<string, true>
     */
    public function findPairKeys(): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.first) AS first', 'IDENTITY(p.second) AS second')
            ->getQuery()
            ->getArrayResult();

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['first'] . ':' . $row['second']] = true;
        }

        return $keys;
    }
}
