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
use App\Enum\TaskCategory;
use App\Enum\TaskIntervalUnit;
use App\Enum\TaskRecurrence;
use App\Enum\TaskUrgency;
use PHPUnit\Framework\TestCase;

final class RecurringTaskUrgencyTest extends TestCase
{
    private const TODAY = '2026-05-10';

    public function testOverdueWhenDueDateHasPassed(): void
    {
        self::assertSame(TaskUrgency::OVERDUE, $this->task('2026-05-09')->urgencyAt($this->today()));
    }

    public function testDueTodayCountsAsApproaching(): void
    {
        self::assertSame(TaskUrgency::DUE_SOON, $this->task(self::TODAY)->urgencyAt($this->today()));
    }

    public function testWithinLeadWindowIsApproaching(): void
    {
        self::assertSame(TaskUrgency::DUE_SOON, $this->task('2026-06-09')->urgencyAt($this->today()));
    }

    public function testOutsideLeadWindowIsFine(): void
    {
        self::assertSame(TaskUrgency::OK, $this->task('2026-06-11')->urgencyAt($this->today()));
    }

    public function testTaskWithoutDateIsUnscheduled(): void
    {
        self::assertSame(TaskUrgency::UNSCHEDULED, $this->task(null)->urgencyAt($this->today()));
    }

    public function testDisabledTaskIsNotWatched(): void
    {
        $task = $this->task('2020-01-01')->setActive(false);

        self::assertSame(TaskUrgency::INACTIVE, $task->urgencyAt($this->today()));
        self::assertFalse($task->urgencyAt($this->today())->needsAttention());
    }

    public function testDaysUntilDueIsNegativeAfterDeadline(): void
    {
        self::assertSame(-3, $this->task('2026-05-07')->daysUntilDue($this->today()));
        self::assertSame(0, $this->task(self::TODAY)->daysUntilDue($this->today()));
        self::assertSame(5, $this->task('2026-05-15')->daysUntilDue($this->today()));
        self::assertNull($this->task(null)->daysUntilDue($this->today()));
    }

    private function task(?string $dueOn): RecurringTask
    {
        $task = new RecurringTask('Zkouška', TaskCategory::REVISION, TaskRecurrence::INTERVAL);
        $task->setInterval(1, TaskIntervalUnit::YEAR)
            ->setLeadDays(30)
            ->setDueOn($dueOn === null ? null : new \DateTimeImmutable($dueOn));

        return $task;
    }

    private function today(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::TODAY);
    }
}
