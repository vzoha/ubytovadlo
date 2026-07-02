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
     * Výdaje v kalendářním roce — pro blok „Obecné výdaje" v Ekonomice.
     *
     * @return LedgerEntry[]
     */
    public function findExpensesInYear(int $year): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
        $to = new \DateTimeImmutable(sprintf('%04d-01-01', $year + 1));

        return $this->createQueryBuilder('e')
            ->andWhere('e.type = :expense')
            ->andWhere('e.occurredOn >= :from')
            ->andWhere('e.occurredOn < :to')
            ->setParameter('expense', \App\Enum\LedgerEntryType::EXPENSE)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('e.occurredOn', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
