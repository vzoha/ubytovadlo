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
use App\Enum\ReservationStatus;
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

    /**
     * Otevřené (naplánované) akce jedné rezervace — vstup pro událostmi řízené
     * uzavírání akcí, jejichž cíl se splnil dřív, než jim nadešel čas.
     *
     * @return ReservationAction[]
     */
    public function findOpenForReservation(Reservation $reservation): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.reservation = :r')
            ->andWhere('a.status = :planned')
            ->setParameter('r', $reservation)
            ->setParameter('planned', ActionStatus::PLANNED)
            ->orderBy('a.scheduledFor', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Akce zrušené se zadaným výsledkem — pro obnovení storna, které vrací mezi
     * naplánované právě ty akce, které storno samo zavřelo.
     *
     * @return ReservationAction[]
     */
    public function findCancelledWithResult(Reservation $reservation, string $result): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.reservation = :r')
            ->andWhere('a.status = :cancelled')
            ->andWhere('a.result = :result')
            ->setParameter('r', $reservation)
            ->setParameter('cancelled', ActionStatus::CANCELLED)
            ->setParameter('result', $result)
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
     * Akce zrušených rezervací vynechává: storno může přijít odkudkoli (UI,
     * MotoPress, iCal) a hostovi zrušeného pobytu už nemá nic odejít.
     *
     * @return ReservationAction[]
     */
    public function findDue(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('a')
            ->join('a.reservation', 'r')
            ->andWhere('a.status = :planned')
            ->andWhere('a.scheduledFor <= :now')
            ->andWhere('r.status != :cancelled')
            ->setParameter('planned', ActionStatus::PLANNED)
            ->setParameter('cancelled', ReservationStatus::CANCELLED)
            ->setParameter('now', $now)
            ->orderBy('a.scheduledFor', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
