<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Reservation;
use App\Enum\InvoiceType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * @return Invoice[]
     */
    public function findForReservation(Reservation $reservation): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.reservation = :r')
            ->setParameter('r', $reservation)
            ->orderBy('i.issuedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Batch načtení faktur pro výpočet ekonomiky — jedna query místo N+1,
     * s eager-fetch zálohové parent faktury (konečná + záloha = celý příjem).
     *
     * @param Reservation[] $reservations
     *
     * @return array<int, Invoice[]> klíč = ID rezervace
     */
    public function findGroupedByReservations(array $reservations): array
    {
        if ($reservations === []) {
            return [];
        }

        /** @var Invoice[] $rows */
        $rows = $this->createQueryBuilder('i')
            ->addSelect('p')
            ->leftJoin('i.parentInvoice', 'p')
            ->andWhere('i.reservation IN (:rs)')
            ->setParameter('rs', $reservations)
            ->orderBy('i.issuedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();

        $byReservation = [];
        foreach ($rows as $invoice) {
            $byReservation[(int) $invoice->getReservation()->getId()][] = $invoice;
        }

        return $byReservation;
    }

    /**
     * Faktura podle variabilního symbolu (= číslo faktury). Slouží ke spárování
     * příchozí platby, když host platí přes QR/SPAYD z faktury.
     */
    public function findOneByVariableSymbol(string $variableSymbol): ?Invoice
    {
        return $this->findOneBy(['variableSymbol' => $variableSymbol]);
    }

    public function findFirstByReservationAndType(Reservation $reservation, InvoiceType $type): ?Invoice
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.reservation = :r')
            ->andWhere('i.type = :t')
            ->setParameter('r', $reservation)
            ->setParameter('t', $type)
            ->orderBy('i.issuedAt', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Vrátí ID rezervací, pro které už byla vystavena faktura některého z daných typů.
     * Hodí se pro detekci "co ještě nemá fakturu" v dashboardu.
     *
     * @param list<InvoiceType> $types
     * @param list<int>         $reservationIds
     *
     * @return list<int>
     */
    public function findReservationIdsWithInvoiceOfType(array $reservationIds, array $types): array
    {
        if ($reservationIds === [] || $types === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.reservation) AS reservationId')
            ->andWhere('i.reservation IN (:ids)')
            ->andWhere('i.type IN (:types)')
            ->setParameter('ids', $reservationIds)
            ->setParameter('types', $types)
            ->groupBy('i.reservation')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['reservationId'], $rows);
    }

    /**
     * Vrátí nejvyšší pořadové číslo v daném roce (poslední 3 cifry z čísla RRRR###).
     *
     * Z výběru jsou vyřazena starší čísla z původní fakturace (formát YYMMDD###, 9 cifer),
     * která jsou v evidenci pro historické zahraniční hosty a nepatří do novější řady.
     */
    public function findHighestSequenceInYear(int $year): int
    {
        $prefix = sprintf('%04d', $year);
        $result = $this->createQueryBuilder('i')
            ->select('i.number')
            ->andWhere('i.seriesYear = :year')
            ->andWhere('LENGTH(i.number) = 7')
            ->andWhere('i.number LIKE :prefix')
            ->setParameter('year', $year)
            ->setParameter('prefix', $prefix . '%')
            ->orderBy('i.number', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($result === null) {
            return 0;
        }

        $seq = substr((string) $result['number'], strlen($prefix));

        return ctype_digit($seq) ? (int) $seq : 0;
    }
}
