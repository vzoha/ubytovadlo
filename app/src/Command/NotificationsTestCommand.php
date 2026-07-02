<?php

/*
 * This file is part of Ubytovadlo.
 *
 * SPDX-License-Identifier: LicenseRef-FSL-1.1-ALv2
 * SPDX-FileCopyrightText: 2026 Vojtěch Žoha
 */

declare(strict_types=1);

namespace App\Command;

use App\Notification\OwnerNotificationSender;
use App\Notification\OwnerNotificationSettingsProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Pošle testovací notifikaci ubytovateli přes reálný mailer — ověření, že
 * SMTP i doručování fungují. Bez `--to` jde na nastavenou adresu příjemce.
 */
#[AsCommand(name: 'app:notifications:test', description: 'Pošle testovací notifikaci ubytovateli (ověření SMTP).')]
final class NotificationsTestCommand extends Command
{
    public function __construct(
        private readonly OwnerNotificationSender $sender,
        private readonly OwnerNotificationSettingsProvider $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, 'Adresa příjemce (jinak nastavený příjemce notifikací).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $to = $input->getOption('to');
        $recipient = is_string($to) && trim($to) !== '' ? trim($to) : $this->settings->recipient();
        if ($recipient === null) {
            $io->error('Není nastavena adresa příjemce — vyplň ji v /nastaveni/notifikace nebo předej --to.');

            return Command::FAILURE;
        }

        try {
            $this->sender->sendTest($recipient);
        } catch (\Throwable $e) {
            $io->error('Odeslání selhalo: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Testovací notifikace odeslána na %s.', $recipient));

        return Command::SUCCESS;
    }
}
