<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Cleaning;
use App\Entity\Reservation;
use App\Enum\CleaningType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cleaning>
 */
class CleaningRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cleaning::class);
    }

    public function findForReservation(Reservation $reservation): ?Cleaning
    {
        return $this->findOneBy(['reservation' => $reservation]);
    }

    /**
     * Batch načtení úklidů pro výpočet ekonomiky — jedna query místo N+1.
     *
     * @param Reservation[] $reservations
     *
     * @return array<int, Cleaning> klíč = ID rezervace
     */
    public function findByReservations(array $reservations): array
    {
        if ($reservations === []) {
            return [];
        }

        /** @var Cleaning[] $rows */
        $rows = $this->createQueryBuilder('c')
            ->andWhere('c.reservation IN (:rs)')
            ->setParameter('rs', $reservations)
            ->getQuery()
            ->getResult();

        $byReservation = [];
        foreach ($rows as $cleaning) {
            $byReservation[(int) $cleaning->getReservation()->getId()] = $cleaning;
        }

        return $byReservation;
    }

    /**
     * Úklidy čekající na vyplacení (payout > 0, paidAt = null), seřazené podle data pobytu.
     *
     * @return Cleaning[]
     */
    public function findPending(): array
    {
        return $this->createQueryBuilder('c')
            ->innerJoin('c.reservation', 'r')
            ->andWhere('c.payoutCzk > 0')
            ->andWhere('c.paidAt IS NULL')
            ->orderBy('r.checkOut', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Vyplacené úklidy v měsíci — pro cashflow přehled „kolik jsem v dubnu zaplatila uklízečce".
     *
     * @return Cleaning[]
     */
    public function findPaidInMonth(int $year, int $month): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to = $from->modify('first day of next month');

        return $this->createQueryBuilder('c')
            ->andWhere('c.paidAt >= :from')
            ->andWhere('c.paidAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('c.paidAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Cleaning[]
     */
    public function findByType(CleaningType $type): array
    {
        return $this->findBy(['type' => $type]);
    }
}
