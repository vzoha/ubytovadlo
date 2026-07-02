<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Enum\OwnerNotificationMode;
use App\Notification\OwnerNotificationSender;
use App\Repository\PendingOwnerNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron (á 15 min) — rozešle okamžité notifikace ubytovateli z fronty, každou
 * jako samostatný e-mail. Idempotentní: odeslané se značí sentAt; při selhání
 * transportu zůstanou ve frontě k dalšímu pokusu.
 */
#[AsCommand(name: 'app:notifications:dispatch', description: 'Rozešle okamžité notifikace ubytovateli z fronty.')]
final class NotificationsDispatchCommand extends Command
{
    public function __construct(
        private readonly PendingOwnerNotificationRepository $pending,
        private readonly OwnerNotificationSender $sender,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $queue = $this->pending->findUnsentByMode(OwnerNotificationMode::IMMEDIATE);

        $sent = 0;
        $failed = 0;
        foreach ($queue as $notification) {
            try {
                $this->sender->sendSingle($notification);
                // Flush hned po každém odeslání — kdyby cron spadl, případná
                // duplicita se omezí na jednu zprávu, ne na celou dávku.
                $notification->markSent();
                $this->em->flush();
                $sent++;
            } catch (\Throwable $e) {
                $failed++;
                $this->logger->error('Odeslání notifikace ubytovateli selhalo.', [
                    'notification' => $notification->getId(),
                    'type' => $notification->getType()->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $io->success(sprintf('Notifikace: odesláno=%d, selhalo=%d', $sent, $failed));

        return Command::SUCCESS;
    }
}
