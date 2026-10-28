<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Customer;
use App\Entity\GuestEmail;
use App\Entity\Reservation;
use App\Enum\EmailDirection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GuestEmail>
 */
class GuestEmailRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GuestEmail::class);
    }

    /**
     * @param list<string> $messageIds
     *
     * @return list<GuestEmail>
     */
    public function findByMessageIds(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        /** @var list<GuestEmail> $found */
        $found = $this->createQueryBuilder('e')
            ->andWhere('e.messageId IN (:ids)')
            ->setParameter('ids', $messageIds)
            ->getQuery()
            ->getResult();

        return $found;
    }

    /**
     * Doručené e-maily od `$since`, od nejnovějšího.
     *
     * @return list<GuestEmail>
     */
    public function findInbound(\DateTimeImmutable $since, bool $includeAnswered, int $limit): array
    {
        $qb = $this->createQueryBuilder('e')
            ->andWhere('e.direction = :in')
            ->andWhere('e.sentAt >= :since')
            ->setParameter('in', EmailDirection::INBOUND)
            ->setParameter('since', $since)
            ->orderBy('e.sentAt', 'DESC')
            ->setMaxResults($limit);
        if (!$includeAnswered) {
            $qb->andWhere('e.answered = false');
        }

        /** @var list<GuestEmail> $found */
        $found = $qb->getQuery()->getResult();

        return $found;
    }

    /** @return list<GuestEmail> od nejstaršího */
    public function findForReservation(Reservation $reservation): array
    {
        return $this->findBy(['reservation' => $reservation], ['sentAt' => 'ASC']);
    }

    /** @return list<GuestEmail> od nejnovějšího */
    public function findForCustomer(Customer $customer): array
    {
        return $this->findBy(['customer' => $customer], ['sentAt' => 'DESC']);
    }

    /**
     * Ostatní e-maily se stejnou adresou hosta, od nejnovějšího.
     *
     * @return list<GuestEmail>
     */
    public function findConversation(GuestEmail $email, int $limit): array
    {
        if ($email->getCounterpartEmail() === null) {
            return [];
        }

        /** @var list<GuestEmail> $found */
        $found = $this->createQueryBuilder('e')
            ->andWhere('e.counterpartEmail = :address')
            ->andWhere('e != :email')
            ->setParameter('address', $email->getCounterpartEmail())
            ->setParameter('email', $email)
            ->orderBy('e.sentAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $found;
    }

    /**
     * E-maily od `$since`, kterým chybí host nebo rezervace — ti mohli přibýt
     * až po stažení, párování se u nich zkouší znovu.
     *
     * @return list<GuestEmail>
     */
    public function findIncompletelyMatched(\DateTimeImmutable $since): array
    {
        /** @var list<GuestEmail> $found */
        $found = $this->createQueryBuilder('e')
            ->andWhere('e.customer IS NULL OR e.reservation IS NULL')
            ->andWhere('e.sentAt >= :since')
            ->setParameter('since', $since)
            ->orderBy('e.sentAt', 'ASC')
            ->getQuery()
            ->getResult();

        return $found;
    }

    /** Při sloučení hostů se korespondence stěhuje s nimi. */
    public function moveToCustomer(Customer $from, Customer $to): void
    {
        foreach ($this->findBy(['customer' => $from]) as $email) {
            $email->moveToCustomer($to);
        }
    }
}
