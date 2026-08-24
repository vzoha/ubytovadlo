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
use App\Entity\TaskCompletion;
use App\Enum\TaskIntervalUnit;
use App\Enum\TaskRecurrence;

/**
 * Počítá příští termín úlohy. Pravidlo se liší podle způsobu opakování:
 * - INTERVAL — interval se přičte k datu provedení,
 * - FIXED_DATE — interval se přičítá k dosavadnímu termínu tak dlouho, až vyjde
 *   datum po provedení; kalendářní termín tak zůstane na svém dni v roce
 *   i u úkonu udělaného dřív nebo se zpožděním,
 * - ONE_OFF — příští termín není, úloha se splněním uzavírá.
 *
 * Platnost z protokolu (`validUntil`) má přednost — revizní zpráva si nese vlastní
 * datum konce platnosti, které se od tabulkové lhůty může lišit.
 */
final class TaskScheduler
{
    /** Pojistka proti nekonečnému posouvání u nesmyslně krátkého intervalu. */
    private const MAX_STEPS = 500;

    /** Příští termín po zapsaném provedení, nebo null, když další termín není. */
    public function nextDueAfter(RecurringTask $task, TaskCompletion $completion): ?\DateTimeImmutable
    {
        $validUntil = $completion->getValidUntil();
        if ($validUntil !== null) {
            return $validUntil;
        }

        if (!$task->getRecurrence()->repeats() || !$task->hasInterval()) {
            return null;
        }

        $doneOn = $completion->getDoneOn();
        if ($task->getRecurrence() === TaskRecurrence::INTERVAL) {
            return $this->advance($doneOn, $task);
        }

        return $this->nextCalendarDue($task, $doneOn);
    }

    /**
     * Termín přepočítaný z historie — používá se po smazání provedení. Kalendářní
     * a jednorázové termíny zůstávají, kde jsou: jejich datum neurčuje historie,
     * ale kalendář, takže by je přepočet posunul mimo skutečnou lhůtu.
     */
    public function recalculate(RecurringTask $task, ?TaskCompletion $latest): ?\DateTimeImmutable
    {
        if ($task->getRecurrence() !== TaskRecurrence::INTERVAL) {
            return $task->getDueOn();
        }
        if ($latest === null || !$task->hasInterval()) {
            return null;
        }

        return $this->nextDueAfter($task, $latest);
    }

    /**
     * Kalendářní termín posunutý o interval, a to i u úkonu udělaného s předstihem —
     * splněná lhůta patří do minulosti, další termín je až ten následující.
     */
    private function nextCalendarDue(RecurringTask $task, \DateTimeImmutable $doneOn): \DateTimeImmutable
    {
        $due = $task->getDueOn() ?? $doneOn;
        $guard = 0;
        do {
            $due = $this->advance($due, $task);
            $guard++;
        } while ($due <= $doneOn && $guard < self::MAX_STEPS);

        return $due;
    }

    private function advance(\DateTimeImmutable $from, RecurringTask $task): \DateTimeImmutable
    {
        $value = (int) $task->getIntervalValue();
        $unit = $task->getIntervalUnit() ?? TaskIntervalUnit::MONTH;

        if ($unit === TaskIntervalUnit::DAY) {
            return $from->modify(sprintf('+%d days', $value));
        }

        $months = $unit === TaskIntervalUnit::YEAR ? $value * 12 : $value;

        return $this->addMonths($from, $months);
    }

    /**
     * Přičtení měsíců s ořezem na poslední den cílového měsíce — `31. 1.` + 1 měsíc
     * je `28. 2.`, ne `3. 3.`, jak by vyšlo z prostého `modify()`.
     */
    private function addMonths(\DateTimeImmutable $from, int $months): \DateTimeImmutable
    {
        $day = (int) $from->format('j');
        $firstOfMonth = $from->modify('first day of this month')->modify(sprintf('+%d months', $months));
        $lastDay = (int) $firstOfMonth->format('t');

        return $firstOfMonth->setDate(
            (int) $firstOfMonth->format('Y'),
            (int) $firstOfMonth->format('n'),
            min($day, $lastDay),
        );
    }
}
