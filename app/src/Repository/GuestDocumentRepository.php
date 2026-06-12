<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\GuestDocument;
use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestDocument>
 */
class GuestDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestDocument::class);
    }

    /**
     * Cizinci k nahlášení v Ubyport za období — pobyt protíná interval [from, to].
     * Filtruje záznamy, které mají všechna povinná pole pro Ubyport
     * (občanství + číslo dokladu); neúplné formuláře export přeskočí.
     *
     * @return GuestDocument[]
     */
    public function findForUbyportExport(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->createQueryBuilder('g')
            ->join('g.reservation', 'r')
            ->andWhere('g.isCzechCitizen = false')
            ->andWhere('g.confirmedAt IS NOT NULL')
            ->andWhere('g.nationalityCode IS NOT NULL')
            ->andWhere('g.documentNumber IS NOT NULL')
            ->andWhere('r.checkIn <= :to')
            ->andWhere('r.checkOut >= :from')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.checkIn', 'ASC')
            ->addOrderBy('g.lastName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Fronta "k nahlášení" — potvrzení cizinci, kteří ještě nebyli nahlášeni
     * a mají všechna povinná pole pro UNL (občanství + číslo dokladu + check-out
     * na rezervaci). Rolling model: po exportu se označí ubyportReportedAt
     * a z fronty zmizí.
     *
     * @return GuestDocument[]
     */
    public function findToReport(): array
    {
        return $this->pendingForeignersQb()
            ->andWhere('g.nationalityCode IS NOT NULL')
            ->andWhere('g.documentNumber IS NOT NULL')
            ->andWhere('r.checkOut IS NOT NULL')
            ->getQuery()
            ->getResult();
    }

    /**
     * Potvrzení cizinci čekající na nahlášení, ale s chybějícím povinným polem
     * (občanství / číslo dokladu / check-out) — do UNL je pustit nelze, je třeba
     * je nejdřív doplnit. Slouží jako upozornění v Ubyport dashboardu.
     *
     * @return GuestDocument[]
     */
    public function findIncompleteToReport(): array
    {
        return $this->pendingForeignersQb()
            ->andWhere('(g.nationalityCode IS NULL OR g.documentNumber IS NULL OR r.checkOut IS NULL)')
            ->getQuery()
            ->getResult();
    }

    /**
     * Společný základ fronty "k nahlášení": potvrzení cizinci bez nahlášení do
     * Ubyportu, řazení podle příjezdu. Volající dál filtruje úplnost povinných
     * polí (viz findToReport / findIncompleteToReport).
     */
    private function pendingForeignersQb(): QueryBuilder
    {
        return $this->createQueryBuilder('g')
            ->join('g.reservation', 'r')
            ->andWhere('g.isCzechCitizen = false')
            ->andWhere('g.confirmedAt IS NOT NULL')
            ->andWhere('g.ubyportReportedAt IS NULL')
            ->orderBy('r.checkIn', 'ASC')
            ->addOrderBy('g.lastName', 'ASC');
    }

    /**
     * Posledních nahlášených cizinců (sestupně podle data nahlášení).
     *
     * @return GuestDocument[]
     */
    public function findRecentlyReported(int $limit = 20): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.ubyportReportedAt IS NOT NULL')
            ->orderBy('g.ubyportReportedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return GuestDocument[]
     */
    public function findByReservation(Reservation $reservation): array
    {
        return $this->createQueryBuilder('g')
            ->andWhere('g.reservation = :reservation')
            ->setParameter('reservation', $reservation)
            ->orderBy('g.lastName', 'ASC')
            ->addOrderBy('g.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
