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
use App\Entity\LedgerEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LedgerEntry>
 */
class LedgerEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LedgerEntry::class);
    }

    /**
     * Všechny pohyby dotýkající se účtu (jako zdroj nebo protistrana převodu),
     * do daného data včetně. Seřazené od nejnovějších.
     *
     * @return LedgerEntry[]
     */
    public function findTouchingAccount(Account $account, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $upTo = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.account = :a OR e.counterAccount = :a')
            ->setParameter('a', $account)
            ->orderBy('e.occurredOn', 'DESC')
            ->addOrderBy('e.id', 'DESC');
        if ($from !== null) {
            $qb->andWhere('e.occurredOn >= :from')->setParameter('from', $from);
        }
        if ($upTo !== null) {
            $qb->andWhere('e.occurredOn <= :upTo')->setParameter('upTo', $upTo);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Všechny pohyby (napříč účty) do daného data — pro chronologický feed a
     * dávkový výpočet zůstatků bez N+1.
     *
     * @return LedgerEntry[]
     */
    public function findAllUpTo(?\DateTimeImmutable $upTo = null): array
    {
        $qb = $this->createQueryBuilder('e')
            ->orderBy('e.occurredOn', 'DESC')
            ->addOrderBy('e.id', 'DESC');
        if ($upTo !== null) {
            $qb->andWhere('e.occurredOn <= :upTo')->setParameter('upTo', $upTo);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Filtrované pohyby pro přehled na /ucty — dle účtu (zdroj i protistrana),
     * typu a období, s limitem/offsetem pro stránkování. Seřazené od nejnovějších.
     *
     * @return LedgerEntry[]
     */
    public function findFiltered(
        ?Account $account = null,
        ?\App\Enum\LedgerEntryType $type = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        int $limit = 20,
        int $offset = 0,
    ): array {
        return $this->filterQuery($account, $type, $from, $to)
            ->orderBy('e.occurredOn', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countFiltered(
        ?Account $account = null,
        ?\App\Enum\LedgerEntryType $type = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
    ): int {
        return (int) $this->filterQuery($account, $type, $from, $to)
            ->select('COUNT(e.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function filterQuery(
        ?Account $account,
        ?\App\Enum\LedgerEntryType $type,
        ?\DateTimeImmutable $from,
        ?\DateTimeImmutable $to,
    ): \Doctrine\ORM\QueryBuilder {
        $qb = $this->createQueryBuilder('e');
        if ($account !== null) {
            $qb->andWhere('e.account = :a OR e.counterAccount = :a')->setParameter('a', $account);
        }
        if ($type !== null) {
            $qb->andWhere('e.type = :type')->setParameter('type', $type);
        }
        if ($from !== null) {
            $qb->andWhere('e.occurredOn >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('e.occurredOn <= :to')->setParameter('to', $to);
        }

        return $qb;
    }

    /**
     * Výdaje v kalendářním roce — pro blok „Obecné výdaje" v Ekonomice.
     *
     * @return LedgerEntry[]
     */
    public function findExpensesInYear(int $year): array
    {
        return $this->findByTypeInYear(\App\Enum\LedgerEntryType::EXPENSE, $year);
    }

    /**
     * Nerezervační příjmy (úroky, storno-poplatky…) v kalendářním roce — pro souhrn.
     *
     * @return LedgerEntry[]
     */
    public function findIncomeInYear(int $year): array
    {
        return $this->findByTypeInYear(\App\Enum\LedgerEntryType::INCOME, $year);
    }

    /**
     * @return LedgerEntry[]
     */
    private function findByTypeInYear(\App\Enum\LedgerEntryType $type, int $year): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.type = :type')
            ->andWhere('e.occurredOn >= :from')
            ->andWhere('e.occurredOn < :to')
            ->setParameter('type', $type)
            ->setParameter('from', new \DateTimeImmutable(sprintf('%04d-01-01', $year)))
            ->setParameter('to', new \DateTimeImmutable(sprintf('%04d-01-01', $year + 1)))
            ->orderBy('e.occurredOn', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
