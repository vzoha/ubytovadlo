<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Twig;

use App\Entity\RecurringTask;
use App\Formatting\TaskInterval;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class TaskExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('task_interval', $this->interval(...)),
        ];
    }

    /** Lhůta opakování slovy — „1× ročně", „1× za 5 let". */
    public function interval(RecurringTask $task): string
    {
        return $task->getRecurrence()->repeats()
            ? TaskInterval::label($task->getIntervalValue(), $task->getIntervalUnit())
            : 'jednorázově';
    }
}
