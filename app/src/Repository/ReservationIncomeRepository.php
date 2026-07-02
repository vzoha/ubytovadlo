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
use App\Entity\ReservationIncome;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReservationIncome>
 */
class ReservationIncomeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReservationIncome::class);
    }

    public function findForReservation(Reservation $reservation): ?ReservationIncome
    {
        return $this->findOneBy(['reservation' => $reservation]);
    }

    /**
     * Batch načtení příjmů pro výpočet ekonomiky — jedna query místo N+1.
     *
     * @param Reservation[] $reservations
     *
     * @return array<int, ReservationIncome> klíč = ID rezervace
     */
    public function findByReservations(array $reservations): array
    {
        if ($reservations === []) {
            return [];
        }

        /** @var ReservationIncome[] $rows */
        $rows = $this->createQueryBuilder('i')
            ->andWhere('i.reservation IN (:rs)')
            ->setParameter('rs', $reservations)
            ->getQuery()
            ->getResult();

        $byReservation = [];
        foreach ($rows as $income) {
            $byReservation[(int) $income->getReservation()->getId()] = $income;
        }

        return $byReservation;
    }

    /**
     * Už přijaté příjmy (datum přijetí ≤ dnes; u OTA = odjezd) — v stavu účtu.
     *
     * @return ReservationIncome[]
     */
    public function findReceived(\DateTimeImmutable $today, int $limit = 30): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.receivedOn IS NOT NULL AND i.receivedOn <= :today')
            ->setParameter('today', $today)
            ->orderBy('i.receivedOn', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Očekávané příjmy (datum přijetí > dnes) — výhled, mimo stav účtu.
     *
     * @return ReservationIncome[]
     */
    public function findExpected(\DateTimeImmutable $today): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.receivedOn IS NULL OR i.receivedOn > :today')
            ->setParameter('today', $today)
            ->orderBy('i.receivedOn', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Příjmy připsané na účet do daného data — pro výpočet zůstatku.
     *
     * @return ReservationIncome[]
     */
    public function findReceivedForAccount(Account $account, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $upTo = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->andWhere('i.account = :a')
            ->andWhere('i.receivedOn IS NOT NULL')
            ->setParameter('a', $account);
        if ($from !== null) {
            $qb->andWhere('i.receivedOn >= :from')->setParameter('from', $from);
        }
        if ($upTo !== null) {
            $qb->andWhere('i.receivedOn <= :upTo')->setParameter('upTo', $upTo);
        }

        return $qb->getQuery()->getResult();
    }
}
