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
use App\Notification\OwnerNotificationSettingsProvider;
use App\Repository\PendingOwnerNotificationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cron (1× denně) — sloučí nasbírané notifikace ubytovateli do jednoho denního
 * souhrnu a odešle. Idempotentní: bez nasbíraných záznamů nic nepošle, odeslané
 * se značí sentAt (opětovné spuštění téhož dne už nic neodešle).
 */
#[AsCommand(name: 'app:notifications:digest', description: 'Odešle denní souhrn notifikací ubytovateli.')]
final class NotificationsDigestCommand extends Command
{
    public function __construct(
        private readonly PendingOwnerNotificationRepository $pending,
        private readonly OwnerNotificationSender $sender,
        private readonly OwnerNotificationSettingsProvider $settings,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Bez příjemce nemá smysl posílat (a sender by hodil výjimku) — necháme
        // frontu ležet, dokud se příjemce nenastaví.
        if ($this->settings->recipient() === null) {
            $io->warning('Není nastavena adresa příjemce notifikací — přeskočeno.');

            return Command::SUCCESS;
        }

        $queue = $this->pending->findUnsentByMode(OwnerNotificationMode::DIGEST);
        if ($queue === []) {
            $io->success('Souhrn: nic k odeslání.');

            return Command::SUCCESS;
        }

        // Selhání transportu neshodí cron — fronta zůstane k dalšímu pokusu.
        try {
            $this->sender->sendDigest($queue);
        } catch (\Throwable $e) {
            $this->logger->error('Odeslání souhrnu notifikací selhalo.', ['error' => $e->getMessage()]);
            $io->warning('Odeslání souhrnu selhalo, zkusí se příště.');

            return Command::SUCCESS;
        }

        foreach ($queue as $notification) {
            $notification->markSent();
        }
        $this->em->flush();

        $io->success(sprintf('Souhrn odeslán: %d položek.', \count($queue)));

        return Command::SUCCESS;
    }
}
