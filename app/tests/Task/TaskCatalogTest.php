<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Task;

use App\Task\TaskCatalog;
use PHPUnit\Framework\TestCase;

final class TaskCatalogTest extends TestCase
{
    private TaskCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new TaskCatalog();
    }

    public function testKeysAreUnique(): void
    {
        $keys = array_map(static fn ($entry): string => $entry->key, $this->catalog->all());

        self::assertSame($keys, array_unique($keys));
    }

    public function testEveryRepeatingEntryHasInterval(): void
    {
        foreach ($this->catalog->all() as $entry) {
            if (!$entry->recurrence->repeats()) {
                continue;
            }
            self::assertNotNull($entry->intervalValue, $entry->key);
            self::assertNotNull($entry->intervalUnit, $entry->key);
            self::assertGreaterThan(0, $entry->intervalValue, $entry->key);
        }
    }

    public function testGroupingCoversAllEntries(): void
    {
        $grouped = $this->catalog->grouped();
        $count = array_sum(array_map('count', $grouped));

        self::assertSame(\count($this->catalog->all()), $count);
    }

    public function testTaskFromTemplateCarriesIntervalAndReference(): void
    {
        $entry = $this->catalog->find('chimney_sweep');
        self::assertNotNull($entry);

        $task = $this->catalog->toTask($entry, new \DateTimeImmutable('2026-09-01'));

        self::assertSame('chimney_sweep', $task->getCatalogKey());
        self::assertSame($entry->name, $task->getName());
        self::assertSame($entry->intervalValue, $task->getIntervalValue());
        self::assertSame($entry->legalReference, $task->getLegalReference());
        self::assertSame('2026-09-01', $task->getDueOn()?->format('Y-m-d'));
        self::assertNotNull($task->getExpenseCategory());
    }

    public function testUnknownKeyIsNotFound(): void
    {
        self::assertNull($this->catalog->find('neexistuje'));
    }

    public function testIntervalLabelReadsInCzech(): void
    {
        $entry = $this->catalog->find('electrical_installation');

        self::assertSame('1× za 5 let', $entry?->intervalLabel());
        self::assertSame('1× ročně', $this->catalog->find('fire_extinguisher_check')?->intervalLabel());
        self::assertSame('1× za 6 měsíců', $this->catalog->find('fire_alarm')?->intervalLabel());
    }
}
