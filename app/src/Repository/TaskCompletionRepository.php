<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecurringTask;
use App\Entity\TaskCompletion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaskCompletion>
 */
class TaskCompletionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaskCompletion::class);
    }

    /** Nejnovější provedení úlohy — z něj se počítá příští termín. */
    public function findLatestForTask(RecurringTask $task): ?TaskCompletion
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.task = :task')
            ->setParameter('task', $task)
            ->orderBy('c.doneOn', 'DESC')
            ->addOrderBy('c.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
