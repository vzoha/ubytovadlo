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
use App\Entity\ReservationNote;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ReservationNote>
 */
class ReservationNoteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ReservationNote::class);
    }

    /**
     * @return ReservationNote[]
     */
    public function findForReservation(Reservation $reservation): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.reservation = :r')
            ->setParameter('r', $reservation)
            ->orderBy('n.occurredAt', 'ASC')
            ->addOrderBy('n.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
