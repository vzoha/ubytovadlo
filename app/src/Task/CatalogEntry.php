<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Task;

use App\Enum\TaskCategory;
use App\Enum\TaskIntervalUnit;
use App\Enum\TaskRecurrence;
use App\Formatting\TaskInterval;

/**
 * Šablona hlídaného termínu v katalogu — název, lhůta a předpis, ze kterého
 * lhůta plyne. `hint` říká, koho se úkon týká, aby se dalo poznat, jestli si ho
 * provozovatel má zapnout.
 */
final readonly class CatalogEntry
{
    public function __construct(
        public string $key,
        public string $name,
        public TaskCategory $category,
        public TaskRecurrence $recurrence,
        public ?int $intervalValue,
        public ?TaskIntervalUnit $intervalUnit,
        public string $hint,
        public ?string $legalReference = null,
    ) {
    }

    /** Lhůta slovy — „1× za 5 let". */
    public function intervalLabel(): string
    {
        return TaskInterval::label($this->intervalValue, $this->intervalUnit);
    }
}
