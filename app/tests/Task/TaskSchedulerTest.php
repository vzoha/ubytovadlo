<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Task;

use App\Entity\RecurringTask;
use App\Entity\TaskCompletion;
use App\Enum\TaskCategory;
use App\Enum\TaskIntervalUnit;
use App\Enum\TaskRecurrence;
use App\Task\TaskScheduler;
use PHPUnit\Framework\TestCase;

final class TaskSchedulerTest extends TestCase
{
    private TaskScheduler $scheduler;

    protected function setUp(): void
    {
        $this->scheduler = new TaskScheduler();
    }

    public function testIntervalCountsFromCompletion(): void
    {
        $task = $this->task(TaskRecurrence::INTERVAL, 1, TaskIntervalUnit::YEAR);
        $task->setDueOn(new \DateTimeImmutable('2026-03-01'));

        $next = $this->scheduler->nextDueAfter($task, new TaskCompletion($task, new \DateTimeImmutable('2026-04-20')));

        self::assertSame('2027-04-20', $next?->format('Y-m-d'));
    }

    public function testMonthIntervalClampsToLastDayOfShorterMonth(): void
    {
        $task = $this->task(TaskRecurrence::INTERVAL, 1, TaskIntervalUnit::MONTH);

        $next = $this->scheduler->nextDueAfter($task, new TaskCompletion($task, new \DateTimeImmutable('2026-01-31')));

        self::assertSame('2026-02-28', $next?->format('Y-m-d'));
    }

    public function testDayIntervalAddsDays(): void
    {
        $task = $this->task(TaskRecurrence::INTERVAL, 14, TaskIntervalUnit::DAY);

        $next = $this->scheduler->nextDueAfter($task, new TaskCompletion($task, new \DateTimeImmutable('2026-05-01')));

        self::assertSame('2026-05-15', $next?->format('Y-m-d'));
    }

    public function testFixedDateKeepsCalendarDayWhenDoneEarly(): void
    {
        $task = $this->task(TaskRecurrence::FIXED_DATE, 1, TaskIntervalUnit::YEAR);
        $task->setDueOn(new \DateTimeImmutable('2026-04-01'));

        $next = $this->scheduler->nextDueAfter($task, new TaskCompletion($task, new \DateTimeImmutable('2026-03-10')));

        self::assertSame('2027-04-01', $next?->format('Y-m-d'));
    }

    public function testFixedDateSkipsMissedPeriodsWhenDoneLate(): void
    {
        $task = $this->task(TaskRecurrence::FIXED_DATE, 3, TaskIntervalUnit::MONTH);
        $task->setDueOn(new \DateTimeImmutable('2026-01-15'));

        $next = $this->scheduler->nextDueAfter($task, new TaskCompletion($task, new \DateTimeImmutable('2026-08-20')));

        self::assertSame('2026-10-15', $next?->format('Y-m-d'));
    }

    public function testValidUntilFromProtocolWinsOverInterval(): void
    {
        $task = $this->task(TaskRecurrence::INTERVAL, 5, TaskIntervalUnit::YEAR);
        $completion = new TaskCompletion($task, new \DateTimeImmutable('2026-02-02'));
        $completion->setValidUntil(new \DateTimeImmutable('2029-06-30'));

        self::assertSame('2029-06-30', $this->scheduler->nextDueAfter($task, $completion)?->format('Y-m-d'));
    }

    public function testOneOffHasNoNextDate(): void
    {
        $task = $this->task(TaskRecurrence::ONE_OFF, null, null);

        self::assertNull($this->scheduler->nextDueAfter($task, new TaskCompletion($task, new \DateTimeImmutable('2026-02-02'))));
    }

    public function testIntervalWithoutValueHasNoNextDate(): void
    {
        $task = $this->task(TaskRecurrence::INTERVAL, null, null);

        self::assertNull($this->scheduler->nextDueAfter($task, new TaskCompletion($task, new \DateTimeImmutable('2026-02-02'))));
    }

    public function testRecalculateDropsDateWhenHistoryIsEmpty(): void
    {
        $task = $this->task(TaskRecurrence::INTERVAL, 1, TaskIntervalUnit::YEAR);
        $task->setDueOn(new \DateTimeImmutable('2027-01-01'));

        self::assertNull($this->scheduler->recalculate($task, null));
    }

    public function testRecalculateKeepsCalendarDate(): void
    {
        $task = $this->task(TaskRecurrence::FIXED_DATE, 1, TaskIntervalUnit::YEAR);
        $task->setDueOn(new \DateTimeImmutable('2027-04-01'));

        self::assertSame('2027-04-01', $this->scheduler->recalculate($task, null)?->format('Y-m-d'));
    }

    private function task(TaskRecurrence $recurrence, ?int $value, ?TaskIntervalUnit $unit): RecurringTask
    {
        $task = new RecurringTask('Zkouška', TaskCategory::REVISION, $recurrence);
        $task->setInterval($value, $unit);

        return $task;
    }
}
