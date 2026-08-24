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
use App\Enum\ExpenseCategory;
use App\Enum\TaskCategory;
use App\Enum\TaskIntervalUnit;
use App\Enum\TaskRecurrence;
use App\Repository\AccountRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Převod formulářového vstupu na entitu termínu a na vstup pro zápis provedení.
 * Drží čtení polí na jednom místě, ať formulář termínu i formulář provedení
 * rozumí datům stejně.
 */
final class TaskFormHydrator
{
    public function __construct(private readonly AccountRepository $accounts)
    {
    }

    /** Název a kategorie jsou povinné — bez nich termín nedává smysl. */
    public function isValid(Request $request): bool
    {
        return $this->text($request, 'name') !== null
            && TaskCategory::tryFrom((string) $request->request->get('category', '')) !== null;
    }

    public function apply(RecurringTask $task, Request $request): RecurringTask
    {
        $recurrence = TaskRecurrence::tryFrom((string) $request->request->get('recurrence', '')) ?? TaskRecurrence::INTERVAL;

        return $task
            ->setName((string) $this->text($request, 'name'))
            ->setCategory(TaskCategory::tryFrom((string) $request->request->get('category', '')) ?? TaskCategory::REVISION)
            ->setRecurrence($recurrence)
            ->setInterval(
                $recurrence->repeats() ? $this->positiveInt($request, 'interval_value') : null,
                TaskIntervalUnit::tryFrom((string) $request->request->get('interval_unit', '')),
            )
            ->setDueOn($this->date($request, 'due_on'))
            ->setLeadDays($this->positiveInt($request, 'lead_days') ?? RecurringTask::DEFAULT_LEAD_DAYS)
            ->setVendor($this->text($request, 'vendor'))
            ->setVendorContact($this->text($request, 'vendor_contact'))
            ->setLegalReference($this->text($request, 'legal_reference'))
            ->setEstimatedCostCzk($this->positiveInt($request, 'estimated_cost'))
            ->setExpenseCategory(ExpenseCategory::tryFrom((string) $request->request->get('expense_category', '')))
            ->setNote($this->text($request, 'note'))
            ->setActive($request->request->getBoolean('active', true));
    }

    public function completionInput(Request $request): CompletionInput
    {
        $file = $request->files->get('attachment');
        $accountId = (int) $request->request->get('book_account', 0);

        return new CompletionInput(
            $this->date($request, 'done_on') ?? new \DateTimeImmutable('today'),
            $this->date($request, 'valid_until'),
            $this->text($request, 'vendor'),
            $this->positiveInt($request, 'cost'),
            $this->text($request, 'note'),
            $file instanceof UploadedFile ? $file : null,
            $accountId > 0 ? $this->accounts->find($accountId) : null,
        );
    }

    /** Vyplněný text, nebo null u prázdného pole. */
    private function text(Request $request, string $field): ?string
    {
        $value = trim((string) $request->request->get($field, ''));

        return $value === '' ? null : $value;
    }

    private function positiveInt(Request $request, string $field): ?int
    {
        $value = trim((string) $request->request->get($field, ''));

        return $value === '' || !is_numeric($value) || (int) $value < 0 ? null : (int) $value;
    }

    private function date(Request $request, string $field): ?\DateTimeImmutable
    {
        $value = trim((string) $request->request->get($field, ''));
        if ($value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
