<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Reservation;
use App\Entity\ReservationAction;
use App\Enum\ActionStatus;
use App\Enum\ActionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReservationAction>
 */
class ReservationActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReservationAction::class);
    }

    /**
     * @return ReservationAction[]
     */
    public function findForReservation(Reservation $reservation): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.reservation = :r')
            ->setParameter('r', $reservation)
            ->orderBy('a.scheduledFor', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function hasOfType(Reservation $reservation, ActionType $type): bool
    {
        $count = (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.reservation = :r')
            ->andWhere('a.type = :t')
            ->setParameter('r', $reservation)
            ->setParameter('t', $type)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Naplánované akce, kterým nadešel čas — vstup pro cron app:actions:run.
     *
     * @return ReservationAction[]
     */
    public function findDue(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :planned')
            ->andWhere('a.scheduledFor <= :now')
            ->setParameter('planned', ActionStatus::PLANNED)
            ->setParameter('now', $now)
            ->orderBy('a.scheduledFor', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
