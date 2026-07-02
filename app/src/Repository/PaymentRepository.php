<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Payment;
use App\Entity\Reservation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function findByEmailMessageId(string $emailMessageId): ?Payment
    {
        return $this->findOneBy(['emailMessageId' => $emailMessageId]);
    }

    /**
     * Všechny (příchozí) platby navázané na rezervaci — reálné bankovní kredity.
     *
     * @return Payment[]
     */
    public function findByReservation(Reservation $reservation): array
    {
        return $this->findBy(['reservation' => $reservation]);
    }

    /**
     * Nepřiřazené příchozí platby v CZK do daného data — reálné kredity, které
     * nejsou u žádné rezervace (do zůstatku bankovního účtu se počítají zvlášť).
     *
     * @return Payment[]
     */
    public function findUnassignedCzk(?\DateTimeImmutable $from = null, ?\DateTimeImmutable $upTo = null): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.reservation IS NULL')
            ->andWhere('p.currency = :czk')
            ->setParameter('czk', 'CZK');
        if ($from !== null) {
            $qb->andWhere('p.receivedAt >= :from')->setParameter('from', $from);
        }
        if ($upTo !== null) {
            $qb->andWhere('p.receivedAt <= :upTo')->setParameter('upTo', $upTo);
        }

        return $qb->getQuery()->getResult();
    }
}
