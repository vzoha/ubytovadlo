<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Tests\Task;

use App\Entity\Account;
use App\Entity\LedgerEntry;
use App\Entity\RecurringTask;
use App\Entity\TaskCompletion;
use App\Enum\AccountType;
use App\Enum\ExpenseCategory;
use App\Enum\TaskCategory;
use App\Enum\TaskIntervalUnit;
use App\Enum\TaskRecurrence;
use App\Repository\LedgerEntryRepository;
use App\Task\CompletionInput;
use App\Task\TaskCompletionRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class TaskCompletionRecorderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TaskCompletionRecorder $recorder;
    private LedgerEntryRepository $ledger;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->recorder = $container->get(TaskCompletionRecorder::class);
        $this->ledger = $container->get(LedgerEntryRepository::class);

        $this->em->createQuery('DELETE FROM ' . TaskCompletion::class . ' c')->execute();
        $this->em->createQuery('DELETE FROM ' . RecurringTask::class . ' t')->execute();
        $this->em->createQuery('DELETE FROM ' . LedgerEntry::class . ' e')->execute();
        $this->em->createQuery('DELETE FROM ' . Account::class . ' a')->execute();
        $this->em->flush();
    }

    public function testCompletionMovesDueDateAndKeepsHistory(): void
    {
        $task = $this->task(TaskRecurrence::INTERVAL, 1, TaskIntervalUnit::YEAR, '2026-03-01');

        $this->recorder->record($task, new CompletionInput(new \DateTimeImmutable('2026-03-05'), vendor: 'Technik'));
        $this->em->flush();

        self::assertSame('2027-03-05', $task->getDueOn()?->format('Y-m-d'));
        self::assertSame('2026-03-05', $task->getLastDoneOn()?->format('Y-m-d'));
        self::assertCount(1, $task->getCompletions());
        self::assertSame('Technik', $task->getCompletions()->first()->getVendor());
    }

    public function testCompletionWithoutVendorFallsBackToTaskVendor(): void
    {
        $task = $this->task(TaskRecurrence::INTERVAL, 1, TaskIntervalUnit::YEAR, '2026-03-01');
        $task->setVendor('Kominík Novák');

        $completion = $this->recorder->record($task, new CompletionInput(new \DateTimeImmutable('2026-03-05')));
        $this->em->flush();

        self::assertSame('Kominík Novák', $completion->getVendor());
    }

    public function testCostIsBookedAsExpenseWhenAccountChosen(): void
    {
        $account = $this->account();
        $task = $this->task(TaskRecurrence::INTERVAL, 1, TaskIntervalUnit::YEAR, '2026-03-01');

        $completion = $this->recorder->record($task, new CompletionInput(
            new \DateTimeImmutable('2026-03-05'),
            vendor: 'Technik',
            costCzk: 1200,
            bookTo: $account,
        ));
        $this->em->flush();

        $entry = $completion->getLedgerEntry();
        self::assertNotNull($entry);
        self::assertSame(1200, $entry->getAmountCzk());
        self::assertSame(ExpenseCategory::MAINTENANCE, $entry->getCategory());
        self::assertSame('2026-03-05', $entry->getOccurredOn()->format('Y-m-d'));
        self::assertStringContainsString('Technik', (string) $entry->getNote());
    }

    public function testCostAloneIsOnlyRecordedWithoutAccount(): void
    {
        $task = $this->task(TaskRecurrence::INTERVAL, 1, TaskIntervalUnit::YEAR, '2026-03-01');

        $completion = $this->recorder->record($task, new CompletionInput(new \DateTimeImmutable('2026-03-05'), costCzk: 900));
        $this->em->flush();

        self::assertSame(900, $completion->getCostCzk());
        self::assertNull($completion->getLedgerEntry());
        self::assertSame([], $this->ledger->findAll());
    }

    public function testOneOffTaskClosesAfterCompletion(): void
    {
        $task = $this->task(TaskRecurrence::ONE_OFF, null, null, '2026-03-01');

        $this->recorder->record($task, new CompletionInput(new \DateTimeImmutable('2026-02-20')));
        $this->em->flush();

        self::assertNull($task->getDueOn());
        self::assertFalse($task->isActive());
    }

    public function testDeletingCompletionRecomputesDueDateAndRemovesExpense(): void
    {
        $account = $this->account();
        $task = $this->task(TaskRecurrence::INTERVAL, 1, TaskIntervalUnit::YEAR, '2026-03-01');

        $this->recorder->record($task, new CompletionInput(new \DateTimeImmutable('2024-03-05')));
        $latest = $this->recorder->record($task, new CompletionInput(
            new \DateTimeImmutable('2026-03-05'),
            costCzk: 1200,
            bookTo: $account,
        ));
        $this->em->flush();
        self::assertCount(1, $this->ledger->findAll());

        $this->recorder->delete($latest);

        self::assertSame('2025-03-05', $task->getDueOn()?->format('Y-m-d'));
        self::assertSame('2024-03-05', $task->getLastDoneOn()?->format('Y-m-d'));
        self::assertSame([], $this->ledger->findAll());
    }

    public function testDeletingLastCompletionLeavesTaskWithoutDate(): void
    {
        $task = $this->task(TaskRecurrence::INTERVAL, 1, TaskIntervalUnit::YEAR, '2026-03-01');
        $completion = $this->recorder->record($task, new CompletionInput(new \DateTimeImmutable('2026-03-05')));
        $this->em->flush();

        $this->recorder->delete($completion);

        self::assertNull($task->getDueOn());
        self::assertNull($task->getLastDoneOn());
    }

    private function task(TaskRecurrence $recurrence, ?int $value, ?TaskIntervalUnit $unit, string $dueOn): RecurringTask
    {
        $task = new RecurringTask('Kontrola komína', TaskCategory::REVISION, $recurrence);
        $task->setInterval($value, $unit)
            ->setExpenseCategory(ExpenseCategory::MAINTENANCE)
            ->setDueOn(new \DateTimeImmutable($dueOn));
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function account(): Account
    {
        $account = new Account('Provozní účet', AccountType::BANK, 0, new \DateTimeImmutable('2026-01-01'));
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
