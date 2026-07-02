<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PendingOwnerNotification;
use App\Enum\OwnerNotificationMode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PendingOwnerNotification>
 */
class PendingOwnerNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PendingOwnerNotification::class);
    }

    /**
     * Neodeslané notifikace daného režimu, nejstarší první.
     *
     * @return list<PendingOwnerNotification>
     */
    public function findUnsentByMode(OwnerNotificationMode $mode): array
    {
        return $this->createQueryBuilder('n')
            ->andWhere('n.deliveryMode = :mode')
            ->andWhere('n.sentAt IS NULL')
            ->setParameter('mode', $mode)
            ->orderBy('n.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
