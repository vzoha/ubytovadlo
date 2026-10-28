<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CheckinEvent;
use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CheckinEvent>
 */
class CheckinEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CheckinEvent::class);
    }

    /**
     * @return CheckinEvent[]
     */
    public function findForReservation(Reservation $reservation): array
    {
        return $this->createQueryBuilder('e')
            ->andWhere('e.reservation = :r')
            ->setParameter('r', $reservation)
            ->orderBy('e.occurredAt', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForReservation(Reservation $reservation): int
    {
        return $this->count(['reservation' => $reservation]);
    }

    public function lastOccurredAt(Reservation $reservation): ?\DateTimeImmutable
    {
        $last = $this->findOneBy(['reservation' => $reservation], ['occurredAt' => 'DESC', 'id' => 'DESC']);

        return $last?->getOccurredAt();
    }
}
