<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Customer\CustomerListRow;
use App\Entity\Customer;
use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Customer>
 */
class CustomerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Customer::class);
    }

    /** @return Customer[] */
    public function findByEmail(string $email): array
    {
        return $this->findBy(['email' => $email], ['id' => 'ASC']);
    }

    /** @return Customer[] */
    public function findByPhone(string $phone): array
    {
        return $this->findBy(['phone' => $phone], ['id' => 'ASC']);
    }

    /**
     * Zákazníci, kteří mají aspoň jednu rezervaci.
     *
     * @return Customer[]
     */
    public function findWithStays(): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('EXISTS (SELECT 1 FROM App\Entity\Reservation r WHERE r.customer = c)')
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Řádky seznamu hostů: zákazník, počet pobytů (bez zrušených) a poslední
     * příjezd; nejdřív ti, kdo byli naposledy. Hledá ve jménu, e-mailu a telefonu.
     *
     * @return list<CustomerListRow>
     */
    public function findForList(?string $search): array
    {
        $qb = $this->createQueryBuilder('c')
            ->select('c AS customer')
            ->addSelect("SUM(CASE WHEN r.status != 'cancelled' THEN 1 ELSE 0 END) AS stays")
            ->addSelect('MAX(r.checkIn) AS lastCheckIn')
            ->innerJoin(Reservation::class, 'r', 'WITH', 'r.customer = c')
            ->groupBy('c.id')
            ->orderBy('lastCheckIn', 'DESC')
            ->addOrderBy('c.id', 'DESC');

        $search = trim((string) $search);
        if ($search !== '') {
            $qb->andWhere('c.displayName LIKE :q OR c.email LIKE :q OR c.phone LIKE :q')
                ->setParameter('q', '%' . addcslashes($search, '%_') . '%');
        }

        return array_map(
            static fn (array $row): CustomerListRow => new CustomerListRow(
                $row['customer'],
                (int) $row['stays'],
                new \DateTimeImmutable((string) $row['lastCheckIn']),
            ),
            $qb->getQuery()->getResult(),
        );
    }
}
