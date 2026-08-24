<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Task;

use App\Entity\Account;
use App\Entity\LedgerEntry;
use App\Entity\RecurringTask;
use App\Entity\TaskCompletion;
use App\Enum\LedgerEntryType;
use App\Enum\TaskRecurrence;
use App\Repository\TaskCompletionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Zápis provedení úkonu: založí řádek historie, posune termín podle způsobu
 * opakování, uloží protokol a volitelně zaúčtuje cenu jako výdaj v cashflow.
 * Jednorázový termín se splněním uzavírá — dál se nehlídá.
 */
final class TaskCompletionRecorder
{
    public function __construct(
        private readonly TaskScheduler $scheduler,
        private readonly TaskAttachmentStorage $attachments,
        private readonly TaskCompletionRepository $completions,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function record(RecurringTask $task, CompletionInput $input): TaskCompletion
    {
        $completion = new TaskCompletion($task, $input->doneOn);
        $completion->setValidUntil($input->validUntil)
            ->setVendor($input->vendor ?? $task->getVendor())
            ->setCostCzk($input->costCzk)
            ->setNote($input->note);

        if ($input->attachment instanceof UploadedFile) {
            $completion->setAttachment(
                $this->attachments->store($input->attachment, $task, $input->doneOn),
                $input->attachment->getClientOriginalName(),
            );
        }

        if ($input->bookTo instanceof Account && $input->costCzk !== null && $input->costCzk > 0) {
            $completion->setLedgerEntry($this->bookExpense($task, $completion, $input->bookTo));
        }

        $this->em->persist($completion);
        $this->applyToTask($task, $completion);

        return $completion;
    }

    /**
     * Smaže provedení i to, co k němu patří — protokol na disku a výdaj, který
     * z něj v cashflow vznikl. Termín se pak přepočítá ze zbylé historie.
     */
    public function delete(TaskCompletion $completion): void
    {
        $task = $completion->getTask();
        $this->attachments->delete($completion->getAttachmentPath());

        $entry = $completion->getLedgerEntry();
        if ($entry !== null) {
            $completion->setLedgerEntry(null);
            $this->em->remove($entry);
        }

        $task->removeCompletion($completion);
        $this->em->remove($completion);
        $this->em->flush();

        $latest = $this->completions->findLatestForTask($task);
        $task->setLastDoneOn($latest?->getDoneOn())
            ->setDueOn($this->scheduler->recalculate($task, $latest));
        $this->em->flush();
    }

    /** Posun termínu a stavu úlohy po zapsaném provedení. */
    private function applyToTask(RecurringTask $task, TaskCompletion $completion): void
    {
        $last = $task->getLastDoneOn();
        if ($last === null || $completion->getDoneOn() > $last) {
            $task->setLastDoneOn($completion->getDoneOn());
        }

        $next = $this->scheduler->nextDueAfter($task, $completion);
        $task->setDueOn($next);

        if ($next === null && $task->getRecurrence() === TaskRecurrence::ONE_OFF) {
            $task->setActive(false);
        }
    }

    private function bookExpense(RecurringTask $task, TaskCompletion $completion, Account $account): LedgerEntry
    {
        $entry = new LedgerEntry(LedgerEntryType::EXPENSE, $completion->getDoneOn(), (int) $completion->getCostCzk(), $account);
        $entry->setCategory($task->getExpenseCategory() ?? $task->getCategory()->defaultExpenseCategory())
            ->setNote($this->expenseNote($task, $completion));
        $this->em->persist($entry);

        return $entry;
    }

    private function expenseNote(RecurringTask $task, TaskCompletion $completion): string
    {
        $vendor = $completion->getVendor();

        return $vendor === null || $vendor === '' ? $task->getName() : $task->getName() . ' — ' . $vendor;
    }
}
