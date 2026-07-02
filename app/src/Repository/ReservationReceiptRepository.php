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
use App\Entity\Reservation;
use App\Entity\ReservationReceipt;
use App\Enum\ReceiptOrigin;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReservationReceipt>
 */
class ReservationReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReservationReceipt::class);
    }

    /**
     * Všechny dílčí platby rezervace, od nejnovějšího přijetí.
     *
     * @return ReservationReceipt[]
     */
    public function findForReservation(Reservation $reservation): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.reservation = :res')
            ->setParameter('res', $reservation)
            ->orderBy('r.receivedOn', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByOrigin(Reservation $reservation, ReceiptOrigin $originType, int $originId): ?ReservationReceipt
    {
        return $this->findOneBy([
            'reservation' => $reservation,
            'originType' => $originType,
            'originId' => $originId,
        ]);
    }

    /**
     * Už přijaté platby (datum přijetí ≤ dnes) — v stavu účtu.
     *
     * @return ReservationReceipt[]
     */
    public function findReceived(\DateTimeImmutable $today, int $limit = 30): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.receivedOn IS NOT NULL AND r.receivedOn <= :today')
            ->setParameter('today', $today)
            ->orderBy('r.receivedOn', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Očekávané platby (datum přijetí > dnes nebo neznámé) — výhled, mimo stav účtu.
     *
     * @return ReservationReceipt[]
     */
    public function findExpected(\DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.receivedOn IS NULL OR r.receivedOn > :today')
            ->setParameter('today', $today)
            ->orderBy('r.receivedOn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Reálně přijaté platby v období [from, to] — pro měsíční souhrn cashflow.
     *
     * @return ReservationReceipt[]
     */
    public function findReceivedBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.receivedOn >= :from')
            ->andWhere('r.receivedOn <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.receivedOn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Platby připsané na účet do daného data — pro výpočet zůstatku.
     *
     * @return ReservationReceipt[]
     */
    public function findReceivedForAccount(Account $account, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $upTo = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->andWhere('r.account = :a')
            ->andWhere('r.receivedOn IS NOT NULL')
            ->setParameter('a', $account);
        if ($from !== null) {
            $qb->andWhere('r.receivedOn >= :from')->setParameter('from', $from);
        }
        if ($upTo !== null) {
            $qb->andWhere('r.receivedOn <= :upTo')->setParameter('upTo', $upTo);
        }

        return $qb->getQuery()->getResult();
    }
}
