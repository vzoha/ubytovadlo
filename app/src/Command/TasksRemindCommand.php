<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Entity\RecurringTask;
use App\Enum\OwnerNotificationType;
use App\Enum\TaskUrgency;
use App\Notification\OwnerNotificationSettingsProvider;
use App\Notification\OwnerNotifier;
use App\Repository\RecurringTaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron (denně) — upozorní na hlídané termíny, do kterých zbývá nejvýš vlastní
 * předstih úlohy, a na termíny, které už uplynuly. Do fronty zakládá notifikace;
 * odesílá je dispatch/digest podle nastaveného režimu.
 *
 * Na každý termín odejde upozornění jednou; po jeho uplynutí se opakuje jednou
 * týdně, dokud se úkon nezapíše jako provedený.
 */
#[AsCommand(name: 'app:tasks:remind', description: 'Upozorní na blížící se a uplynulé hlídané termíny.')]
final class TasksRemindCommand extends Command
{
    /** Jak často se opakuje upozornění na termín, který už uplynul. */
    private const OVERDUE_REPEAT_DAYS = 7;

    public function __construct(
        private readonly RecurringTaskRepository $tasks,
        private readonly OwnerNotifier $notifier,
        private readonly OwnerNotificationSettingsProvider $settings,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('today', null, InputOption::VALUE_REQUIRED, 'Datum, ke kterému se termíny vyhodnotí (jinak dnešek).')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Upozorni i na termíny, na které už upozornění odešlo.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $today = $this->resolveToday($input);
        $force = (bool) $input->getOption('force');

        if ($this->settings->recipient() === null) {
            $io->warning('Není nastavena adresa příjemce notifikací — přeskočeno.');

            return Command::SUCCESS;
        }

        $queued = 0;
        foreach ($this->tasks->findDue($today) as $task) {
            if (!$force && !$this->shouldRemind($task, $today)) {
                continue;
            }
            if (!$this->notifier->notify(OwnerNotificationType::TASK_DUE, null, $this->payload($task, $today))) {
                continue;
            }
            $task->markReminded($today);
            $queued++;
            $io->text(sprintf('%s — termín %s', $task->getName(), $task->getDueOn()?->format('j. n. Y') ?? '—'));
        }
        $this->em->flush();

        $io->success(sprintf('Zařazeno upozornění na %d termínů.', $queued));

        return Command::SUCCESS;
    }

    /**
     * Poprvé při vstupu do okna upozornění, pak znovu jen u termínu po lhůtě,
     * a to nejvýš jednou za týden — ať připomínka nepřejde v denní šum.
     */
    private function shouldRemind(RecurringTask $task, \DateTimeImmutable $today): bool
    {
        $due = $task->getDueOn();
        $reminded = $task->getRemindedForDue();
        if ($due === null) {
            return false;
        }
        if ($reminded === null || $reminded->format('Y-m-d') !== $due->format('Y-m-d')) {
            return true;
        }
        if ($task->urgencyAt($today) !== TaskUrgency::OVERDUE) {
            return false;
        }

        $last = $task->getLastRemindedOn();

        return $last === null || $last <= $today->modify(sprintf('-%d days', self::OVERDUE_REPEAT_DAYS));
    }

    /** @return array<string, mixed> */
    private function payload(RecurringTask $task, \DateTimeImmutable $today): array
    {
        return [
            'taskId' => $task->getId(),
            'name' => $task->getName(),
            'dueOn' => $task->getDueOn()?->format('j. n. Y'),
            'days' => $task->daysUntilDue($today),
            'legalReference' => $task->getLegalReference(),
            'vendor' => $task->getVendor(),
        ];
    }

    private function resolveToday(InputInterface $input): \DateTimeImmutable
    {
        $raw = $input->getOption('today');
        if (!is_string($raw) || trim($raw) === '') {
            return new \DateTimeImmutable('today');
        }

        return (new \DateTimeImmutable($raw))->setTime(0, 0);
    }
}
