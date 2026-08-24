<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Task;

use App\Entity\RecurringTask;

/**
 * Termíny vyžadující pozornost pro kartu přehledu — zkrácený seznam a počty.
 */
final readonly class TaskSummary
{
    /** @param list<RecurringTask> $tasks */
    public function __construct(
        public array $tasks,
        public int $overdue,
        public int $soon,
        public int $total,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }
}
