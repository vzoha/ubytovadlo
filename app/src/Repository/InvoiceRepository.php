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
use App\Formatting\Money;
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
     * Součet zaplacených faktur v Kč po rezervacích (dávkově, bez N+1).
     * Jen CZK faktury — EUR (Booking) se s Kč cenou nesčítá.
     *
     * @param int[] $reservationIds
     *
     * @return array<int, float> reservationId => součet CZK
     */
    public function sumPaidCzkByReservations(array $reservationIds): array
    {
        if ($reservationIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('i')
            ->select('IDENTITY(i.reservation) AS rid', 'SUM(i.totalAmount) AS total')
            ->andWhere('i.reservation IN (:ids)')
            ->andWhere('i.paidAt IS NOT NULL')
            ->andWhere('i.currency = :czk')
            ->setParameter('ids', $reservationIds)
            ->setParameter('czk', 'CZK')
            ->groupBy('i.reservation')
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['rid']] = (float) $row['total'];
        }

        return $out;
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
     * Součet výstupní DPH z faktur hostům vystavených v daném měsíci (plátce DPH).
     * Bere jen faktury se snímkem DPH (vat_amount_total not null) — ty jsou vždy v CZK.
     *
     * @return array{base: string, vat: string} součty v CZK, scale 2
     */
    public function sumOutputVatByIssuedMonth(int $year, int $month): array
    {
        [$from, $to] = self::monthRange($year, $month);

        $row = $this->createQueryBuilder('i')
            ->select('SUM(i.vatBaseTotal) AS base', 'SUM(i.vatAmountTotal) AS vat')
            ->andWhere('i.vatAmountTotal IS NOT NULL')
            ->andWhere('i.issuedAt >= :from')
            ->andWhere('i.issuedAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getSingleResult();

        return [
            'base' => Money::normalize((float) ($row['base'] ?? 0)),
            'vat' => Money::normalize((float) ($row['vat'] ?? 0)),
        ];
    }

    /**
     * Měsíce (klíč „Y-m"), ve kterých byla vystavena aspoň jedna faktura s výstupní DPH.
     * Podklad pro seznam DPH období u plátce (faktury bez OTA provize).
     *
     * @return list<string>
     */
    public function findMonthsWithOutputVat(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('DISTINCT SUBSTRING(i.issuedAt, 1, 7) AS ym')
            ->andWhere('i.vatAmountTotal IS NOT NULL')
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): string => (string) $row['ym'], $rows);
    }

    /**
     * Faktury hostům vystavené v daném měsíci (podklad DPH — výstupní doklady).
     *
     * @return Invoice[]
     */
    public function findIssuedInMonth(int $year, int $month): array
    {
        [$from, $to] = self::monthRange($year, $month);

        return $this->createQueryBuilder('i')
            ->andWhere('i.issuedAt >= :from')
            ->andWhere('i.issuedAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('i.issuedAt', 'ASC')
            ->addOrderBy('i.seriesSequence', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Polootevřený interval měsíce [první den; první den následujícího měsíce).
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private static function monthRange(int $year, int $month): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        return [$from, $from->modify('first day of next month')];
    }

    /**
     * Vrátí nejvyšší pořadové číslo v daném roce (poslední 3 cifry z čísla RRRR###).
     *
     * Z výběru jsou vyřazena starší čísla z původní fakturace (formát YYMMDD###, 9 cifer),
     * která jsou v evidenci pro historické zahraniční hosty a nepatří do novější řady.
     */
    public function findHighestSequenceInYear(int $year): int
    {
        $max = $this->createQueryBuilder('i')
            ->select('MAX(i.seriesSequence)')
            ->andWhere('i.seriesYear = :year')
            ->setParameter('year', $year)
            ->getQuery()
            ->getSingleScalarResult();

        return $max === null ? 0 : (int) $max;
    }
}
