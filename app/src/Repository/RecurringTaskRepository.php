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
use App\Enum\TaskCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringTask>
 */
class RecurringTaskRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringTask::class);
    }

    /**
     * Všechny termíny pro seznam, hlídané i vypnuté.
     *
     * @return RecurringTask[]
     */
    public function findForListing(?TaskCategory $category = null): array
    {
        return $this->listingQuery($category)->getQuery()->getResult();
    }

    /**
     * Jen hlídané termíny — výchozí pohled seznamu.
     *
     * @return RecurringTask[]
     */
    public function findActiveForListing(?TaskCategory $category = null): array
    {
        return $this->listingQuery($category)->andWhere('t.active = true')->getQuery()->getResult();
    }

    /**
     * Řazení seznamu: nejbližší termín nahoře, termín bez data na konci —
     * Doctrine řadí NULL napřed, proto se řadí přes vypočtený příznak.
     */
    private function listingQuery(?TaskCategory $category): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->addSelect('CASE WHEN t.dueOn IS NULL THEN 1 ELSE 0 END AS HIDDEN undated')
            ->orderBy('undated', 'ASC')
            ->addOrderBy('t.dueOn', 'ASC')
            ->addOrderBy('t.name', 'ASC');
        if ($category !== null) {
            $qb->andWhere('t.category = :category')->setParameter('category', $category);
        }

        return $qb;
    }

    /**
     * Aktivní termíny, které spadají do okna upozornění (do `dueOn` zbývá nejvýš
     * vlastní předstih úlohy) — včetně těch po termínu.
     *
     * @return RecurringTask[]
     */
    public function findDue(\DateTimeImmutable $today): array
    {
        $tasks = $this->createQueryBuilder('t')
            ->andWhere('t.active = true')
            ->andWhere('t.dueOn IS NOT NULL')
            ->orderBy('t.dueOn', 'ASC')
            ->getQuery()
            ->getResult();

        return array_values(array_filter(
            $tasks,
            static fn (RecurringTask $task): bool => $task->urgencyAt($today)->needsAttention(),
        ));
    }

    /** @return array<string, RecurringTask> klíč katalogu → úloha */
    public function findByCatalogKeys(): array
    {
        $tasks = $this->createQueryBuilder('t')
            ->andWhere('t.catalogKey IS NOT NULL')
            ->getQuery()
            ->getResult();

        $byKey = [];
        foreach ($tasks as $task) {
            $key = $task->getCatalogKey();
            if ($key !== null) {
                $byKey[$key] = $task;
            }
        }

        return $byKey;
    }
}
