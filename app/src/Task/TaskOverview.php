<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Task;

use App\Enum\TaskUrgency;
use App\Repository\RecurringTaskRepository;

/**
 * Souhrn hlídaných termínů pro přehled — co je po termínu a co se blíží.
 */
final class TaskOverview
{
    /** Kolik termínů se vejde na kartu přehledu, než se odkáže na celý seznam. */
    private const DASHBOARD_LIMIT = 6;

    public function __construct(private readonly RecurringTaskRepository $tasks)
    {
    }

    public function summary(\DateTimeImmutable $today, int $limit = self::DASHBOARD_LIMIT): TaskSummary
    {
        $due = $this->tasks->findDue($today);
        $overdue = 0;
        foreach ($due as $task) {
            if ($task->urgencyAt($today) === TaskUrgency::OVERDUE) {
                $overdue++;
            }
        }

        return new TaskSummary(
            array_slice($due, 0, $limit),
            $overdue,
            \count($due) - $overdue,
            \count($due),
        );
    }
}
